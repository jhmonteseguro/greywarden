#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Write an AWS account's inventory as a CSV for `make import-cloud PROVIDER=aws`,
# using your local AWS CLI v2 and a profile from ~/.aws (read-only calls only:
# the ReadOnlyAccess managed policy is enough; Resource Explorer and AWS Config
# are not needed). Every resource type the import keeps is collected, in every
# Region enabled for the account.
#
#   scripts/aws-inventory.sh                       the default profile (or $AWS_PROFILE)
#   scripts/aws-inventory.sh --profile prod        another profile from ~/.aws
#   scripts/aws-inventory.sh --pick                choose the profile from a menu
#   scripts/aws-inventory.sh --list-profiles       list the profiles and exit
#   scripts/aws-inventory.sh --regions eu-west-2,us-east-1   only these Regions
#   scripts/aws-inventory.sh --output aws.csv      file name (default aws-<profile>-<account>-<date>.csv)
#   scripts/aws-inventory.sh --jobs 8              Regions collected at once (default 4)
#
# Then: make import-cloud FILE=aws.csv PROVIDER=aws AS=<username> DRY_RUN=1
# ---------------------------------------------------------------------------
set -euo pipefail

PROFILE="${AWS_PROFILE:-default}"; PICK=0; REGIONS=""; OUTPUT=""; JOBS=4
while [ $# -gt 0 ]; do
    case "$1" in
        -p|--profile) PROFILE="${2:?--profile needs a name}"; shift ;;
        --profile=*) PROFILE="${1#*=}" ;;
        --pick) PICK=1 ;;
        --list-profiles) aws configure list-profiles; exit $? ;;
        -r|--regions) REGIONS="${2:?--regions needs a list}"; shift ;;
        --regions=*) REGIONS="${1#*=}" ;;
        -o|--output) OUTPUT="${2:?--output needs a file name}"; shift ;;
        --output=*) OUTPUT="${1#*=}" ;;
        -j|--jobs) JOBS="${2:?--jobs needs a number}"; shift ;;
        --jobs=*) JOBS="${1#*=}" ;;
        -h|--help) sed -n '3,18p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1 (try --help)" >&2; exit 1 ;;
    esac
    shift
done
case "$JOBS" in ''|*[!0-9]*|0) echo "--jobs must be a positive number" >&2; exit 1 ;; esac

command -v aws >/dev/null || { echo "The AWS CLI is not installed (https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html)" >&2; exit 1; }
case "$(aws --version 2>&1)" in
    aws-cli/2.*) ;;
    *) echo "AWS CLI v2 is required (found: $(aws --version 2>&1))" >&2; exit 1 ;;
esac

if [ "$PICK" = 1 ]; then
    PROFILES=$(aws configure list-profiles)
    [ -n "$PROFILES" ] || { echo "No profiles in ~/.aws (run aws configure)" >&2; exit 1; }
    PS3="Profile: "
    # shellcheck disable=SC2086 # profile names have no spaces
    select PROFILE in $PROFILES; do [ -n "$PROFILE" ] && break; done
    [ -n "$PROFILE" ] || exit 1
fi

export AWS_PAGER=""
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
touch "$WORK/warnings"

# aws_text <region> <service> <operation> [args...]: text output of one call
aws_text() {
    local region=$1; shift
    aws --profile "$PROFILE" --region "$region" --output text "$@"
}

HOME_REGION=${AWS_REGION:-$(aws configure get region --profile "$PROFILE" 2>/dev/null || true)}
if ! IDENTITY=$(aws_text "${HOME_REGION:-us-east-1}" sts get-caller-identity --query '[Account, Arn]' 2>"$WORK/err"); then
    echo "Cannot use profile '$PROFILE':" >&2; sed 's/^/  /' "$WORK/err" >&2
    echo "(SSO profiles: aws sso login --profile $PROFILE)" >&2
    exit 1
fi
ACCOUNT=$(printf '%s' "$IDENTITY" | cut -f1)
CALLER=$(printf '%s' "$IDENTITY" | cut -f2)
PARTITION=$(printf '%s' "$CALLER" | cut -d: -f2)
case "$PARTITION" in
    aws-cn) HOME_REGION=${HOME_REGION:-cn-north-1} ;;
    aws-us-gov) HOME_REGION=${HOME_REGION:-us-gov-west-1} ;;
    *) HOME_REGION=${HOME_REGION:-us-east-1} ;;
esac

if [ -n "$REGIONS" ]; then
    REGIONS=$(printf '%s' "$REGIONS" | tr ',' ' ')
elif ! REGIONS=$(aws_text "$HOME_REGION" ec2 describe-regions --query 'Regions[].[RegionName]' 2>"$WORK/err"); then
    echo "Cannot list the account's Regions (use --regions):" >&2; sed 's/^/  /' "$WORK/err" >&2; exit 1
fi
REGIONS=$(printf '%s\n' $REGIONS | sort -u | tr '\n' ' ')

echo "Profile $PROFILE: account $ACCOUNT as $CALLER" >&2
echo "Regions: $REGIONS" >&2

# warn <region> <what> <stderr file>: a call that failed (Regions without the service are skipped quietly)
warn() {
    grep -q 'Could not connect to the endpoint URL' "$3" && return 0
    printf '%s\t%s\t%s\n' "$1" "$2" "$(grep -v '^$' "$3" | head -n 1)" >>"$WORK/warnings"
}

# collect <type> <region> <ARN template> <service> <operation> <query> [args...]
# The query returns rows of [id, name, public IP, Region]; trailing fields may be left out.
# The template builds the ARN from {id} (or {last}, the id's last "/" part); "" when the id is the ARN.
# Writes rows of: type, Region, ARN, name, public IP (tab separated); ROW_REGION=global for global services.
collect() {
    local type=$1 region=$2 template=$3 service=$4 operation=$5 query=$6 err out
    shift 6
    err=$(mktemp "$WORK/err.XXXXXX")
    if ! out=$(aws_text "$region" "$service" "$operation" --query "$query" "$@" 2>"$err"); then
        warn "$region" "$service $operation" "$err"; return 0
    fi
    [ -n "$out" ] || return 0
    printf '%s\n' "$out" | awk -F'\t' -v OFS='\t' -v type="$type" -v region="${ROW_REGION:-$region}" -v tpl="$template" \
        -v P="$PARTITION" -v R="$region" -v A="$ACCOUNT" '
        function replace(s, from, to,   i) {
            while ((i = index(s, from)) > 0) s = substr(s, 1, i - 1) to substr(s, i + length(from))
            return s
        }
        {
            for (i = 1; i <= 4; i++) if ($i == "None") $i = ""
            if ($1 == "") next
            n = split($1, parts, "/")
            arn = $1
            if (tpl != "") {
                arn = replace(replace(replace(replace(replace(tpl, "{P}", P), "{R}", R), "{A}", A), "{id}", $1), "{last}", parts[n])
            }
            t = type
            if (t == "elbv2") {
                t = "elasticloadbalancing:loadbalancer"
                if (match(arn, /:loadbalancer\/(app|net|gwy)\//)) t = t "/" substr(arn, RSTART + 14, 3)
            }
            name = $2; sub(/\.$/, "", name)
            print t, ($4 != "" ? $4 : region), arn, name, $3
        }'
}

# KMS: customer managed keys only (AWS managed keys have alias/aws/...), named after their first alias
collect_kms() {
    local region=$1 err
    err=$(mktemp "$WORK/err.XXXXXX")
    if ! aws_text "$region" kms list-aliases --query 'Aliases[?TargetKeyId].[TargetKeyId, AliasName]' >"$WORK/kms-aliases.$region" 2>"$err" \
        || ! aws_text "$region" kms list-keys --query 'Keys[].[KeyId, KeyArn]' >"$WORK/kms-keys.$region" 2>"$err"; then
        warn "$region" "kms list-keys" "$err"; return 0
    fi
    awk -F'\t' -v OFS='\t' -v region="$region" '
        FNR == NR { if ($2 ~ /^alias\/aws\//) managed[$1] = 1; else if (!($1 in name)) name[$1] = substr($2, 7); next }
        $1 != "" && !($1 in managed) { print "kms:key", region, $2, name[$1], "" }
    ' "$WORK/kms-aliases.$region" "$WORK/kms-keys.$region"
}

TAG_NAME="Tags[?Key=='Name'].Value | [0]"

collect_region() {
    local r=$1 arn="arn:{P}:ec2:{R}:{A}"
    collect ec2:instance "$r" "$arn:instance/{id}" ec2 describe-instances \
        "Reservations[].Instances[].[InstanceId, $TAG_NAME, PublicIpAddress]" \
        --filters Name=instance-state-name,Values=pending,running,stopping,stopped,shutting-down
    collect ec2:volume "$r" "$arn:volume/{id}" ec2 describe-volumes "Volumes[].[VolumeId, $TAG_NAME]"
    collect ec2:vpc "$r" "$arn:vpc/{id}" ec2 describe-vpcs "Vpcs[].[VpcId, $TAG_NAME]"
    collect ec2:security-group "$r" "$arn:security-group/{id}" ec2 describe-security-groups \
        "SecurityGroups[].[GroupId, not_null($TAG_NAME, GroupName)]"
    collect ec2:network-acl "$r" "$arn:network-acl/{id}" ec2 describe-network-acls "NetworkAcls[].[NetworkAclId, $TAG_NAME]"
    collect ec2:internet-gateway "$r" "$arn:internet-gateway/{id}" ec2 describe-internet-gateways \
        "InternetGateways[].[InternetGatewayId, $TAG_NAME]"
    collect ec2:natgateway "$r" "$arn:natgateway/{id}" ec2 describe-nat-gateways \
        "NatGateways[?State!='deleted'].[NatGatewayId, $TAG_NAME, NatGatewayAddresses[0].PublicIp]"
    collect ec2:transit-gateway "$r" "" ec2 describe-transit-gateways \
        "TransitGateways[?State!='deleted'].[TransitGatewayArn, $TAG_NAME]"
    collect ec2:vpn-connection "$r" "$arn:vpn-connection/{id}" ec2 describe-vpn-connections \
        "VpnConnections[?State!='deleted'].[VpnConnectionId, $TAG_NAME]"
    collect ec2:vpn-gateway "$r" "$arn:vpn-gateway/{id}" ec2 describe-vpn-gateways \
        "VpnGateways[?State!='deleted'].[VpnGatewayId, $TAG_NAME]"
    collect ec2:elastic-ip "$r" "$arn:elastic-ip/{id}" ec2 describe-addresses "Addresses[].[AllocationId, $TAG_NAME, PublicIp]"
    collect elbv2 "$r" "" elbv2 describe-load-balancers "LoadBalancers[].[LoadBalancerArn, LoadBalancerName]"
    collect elasticloadbalancing:loadbalancer "$r" "arn:{P}:elasticloadbalancing:{R}:{A}:loadbalancer/{id}" elb describe-load-balancers \
        "LoadBalancerDescriptions[].[LoadBalancerName, LoadBalancerName]"
    collect eks:cluster "$r" "arn:{P}:eks:{R}:{A}:cluster/{id}" eks list-clusters "clusters[].[@, @]"
    collect ecs:cluster "$r" "" ecs list-clusters "clusterArns[].[@]"
    collect lambda:function "$r" "" lambda list-functions "Functions[].[FunctionArn, FunctionName]"
    collect ecr:repository "$r" "" ecr describe-repositories "repositories[].[repositoryArn, repositoryName]"
    collect apigateway:restapis "$r" "arn:{P}:apigateway:{R}::/restapis/{id}" apigateway get-rest-apis "items[].[id, name]"
    collect apigateway:apis "$r" "arn:{P}:apigateway:{R}::/apis/{id}" apigatewayv2 get-apis "Items[].[ApiId, Name]"
    collect elasticfilesystem:file-system "$r" "" efs describe-file-systems "FileSystems[].[FileSystemArn, Name]"
    collect fsx:file-system "$r" "" fsx describe-file-systems "FileSystems[].[ResourceARN, $TAG_NAME]"
    collect backup:backup-vault "$r" "" backup list-backup-vaults "BackupVaultList[].[BackupVaultArn, BackupVaultName]"
    collect rds:db "$r" "" rds describe-db-instances "DBInstances[].[DBInstanceArn, DBInstanceIdentifier]"
    collect rds:cluster "$r" "" rds describe-db-clusters "DBClusters[].[DBClusterArn, DBClusterIdentifier]"
    collect dynamodb:table "$r" "arn:{P}:dynamodb:{R}:{A}:table/{id}" dynamodb list-tables "TableNames[].[@, @]"
    collect es:domain "$r" "arn:{P}:es:{R}:{A}:domain/{id}" opensearch list-domain-names "DomainNames[].[DomainName, DomainName]"
    collect redshift:cluster "$r" "arn:{P}:redshift:{R}:{A}:cluster:{id}" redshift describe-clusters \
        "Clusters[].[ClusterIdentifier, ClusterIdentifier]"
    collect elasticache:cluster "$r" "" elasticache describe-cache-clusters "CacheClusters[].[ARN, CacheClusterId]"
    collect elasticache:replicationgroup "$r" "" elasticache describe-replication-groups \
        "ReplicationGroups[].[ARN, ReplicationGroupId]"
    collect sqs:queue "$r" "arn:{P}:sqs:{R}:{A}:{last}" sqs list-queues "QueueUrls[].[@]"
    collect sns:topic "$r" "" sns list-topics "Topics[].[TopicArn]"
    collect kinesis:stream "$r" "" kinesis list-streams "StreamSummaries[].[StreamARN, StreamName]"
    collect kafka:cluster "$r" "" kafka list-clusters-v2 "ClusterInfoList[].[ClusterArn, ClusterName]"
    collect_kms "$r"
    collect secretsmanager:secret "$r" "" secretsmanager list-secrets "SecretList[].[ARN, Name]"
    collect acm:certificate "$r" "" acm list-certificates "CertificateSummaryList[].[CertificateArn, DomainName]"
    collect wafv2:regional/webacl "$r" "" wafv2 list-web-acls "WebACLs[].[ARN, Name]" --scope REGIONAL
    collect codecommit:repository "$r" "arn:{P}:codecommit:{R}:{A}:{id}" codecommit list-repositories \
        "repositories[].[repositoryName, repositoryName]"
}

collect_global() {
    local r=$HOME_REGION
    export ROW_REGION=global
    collect s3:bucket "$r" "arn:{P}:s3:::{id}" s3api list-buckets 'Buckets[].[Name, Name, `null`, BucketRegion]'
    # Service-linked roles belong to AWS services, not to you
    collect iam:role "$r" "" iam list-roles "Roles[?!starts_with(Path, '/aws-service-role/')].[Arn, RoleName]"
    collect iam:user "$r" "" iam list-users "Users[].[Arn, UserName]"
    collect cloudfront:distribution "$r" "" cloudfront list-distributions "DistributionList.Items[].[ARN, Comment || DomainName]"
    collect route53:hostedzone "$r" "arn:{P}:route53:::hostedzone/{last}" route53 list-hosted-zones "HostedZones[].[Id, Name]"
    if [ "$PARTITION" = aws ]; then
        collect wafv2:global/webacl us-east-1 "" wafv2 list-web-acls "WebACLs[].[ARN, Name]" --scope CLOUDFRONT
    fi
}

# Global services first, then the Regions, $JOBS at a time
collect_global >"$WORK/rows.global" &
running=1
for region in $REGIONS; do
    collect_region "$region" >"$WORK/rows.$region" &
    running=$((running + 1))
    if [ "$running" -ge "$JOBS" ]; then wait; running=0; fi
done
wait

OUTPUT=${OUTPUT:-aws-$PROFILE-$ACCOUNT-$(date +%Y%m%d).csv}
umask 077
LC_ALL=C sort -u "$WORK"/rows.* | awk -F'\t' -v account="$ACCOUNT" '
    function csv(s) { if (s ~ /[",\r\n]/) { gsub(/"/, "\"\"", s); s = "\"" s "\"" } return s }
    BEGIN { print "ARN,Resource type,Region,Account ID,Name,Public IP" }
    { print csv($3) "," csv($1) "," csv($2) "," account "," csv($4) "," csv($5) }
' >"$WORK/out.csv"
mv "$WORK/out.csv" "$OUTPUT"

echo >&2
echo "Resources by type:" >&2
cut -f1 "$WORK"/rows.* | LC_ALL=C sort | uniq -c | sed 's/^ */  /' >&2
echo "Wrote $(($(wc -l <"$OUTPUT") - 1)) resources to $OUTPUT" >&2
if [ -s "$WORK/warnings" ]; then
    echo >&2
    echo "Not collected ($(wc -l <"$WORK/warnings") calls failed; missing permissions or the service is not enabled):" >&2
    LC_ALL=C sort "$WORK/warnings" | sed 's/^/  /' >&2
fi
echo >&2
echo "Next: make import-cloud FILE=$OUTPUT PROVIDER=aws AS=<username> DRY_RUN=1" >&2
