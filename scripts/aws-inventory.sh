#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Write an AWS account's inventory as a CSV for `make import-cloud PROVIDER=aws`,
# using your local AWS CLI v2 and a profile from ~/.aws (read-only calls only:
# the ReadOnlyAccess managed policy is enough; Resource Explorer and AWS Config
# are not needed). Every resource type the import keeps is collected, in every
# Region enabled for the account, with the security settings of each resource
# (public access, encryption, open ports, MFA, key age...) and of the account
# (root MFA, CloudTrail, GuardDuty, S3 Block Public Access...). Greywarden turns
# them into the asset's configuration and findings.
#
# AWS's own plumbing is left out unless you add --all: default VPCs that nothing
# uses (with their security group, network ACL and internet gateway; listed on
# the account instead), default network ACLs, and the roles AWS services and IAM
# Identity Center create for themselves.
#
#   scripts/aws-inventory.sh                       the default profile (or $AWS_PROFILE)
#   scripts/aws-inventory.sh --profile prod        another profile from ~/.aws
#   scripts/aws-inventory.sh --pick                choose the profile from a menu
#   scripts/aws-inventory.sh --list-profiles       list the profiles and exit
#   scripts/aws-inventory.sh --regions eu-west-2,us-east-1   only these Regions
#   scripts/aws-inventory.sh --output aws.csv      file name (default aws-<profile>-<account>-<date>.csv)
#   scripts/aws-inventory.sh --jobs 8              Regions (and buckets) collected at once (default 4)
#   scripts/aws-inventory.sh --all                 keep AWS's default and service-managed resources too
#
# Needs jq. Then: make import-cloud FILE=aws.csv PROVIDER=aws AS=<username> DRY_RUN=1
# ---------------------------------------------------------------------------
set -euo pipefail

PROFILE="${AWS_PROFILE:-default}"; PICK=0; REGIONS=""; OUTPUT=""; JOBS=4; ALL=0
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
        -a|--all) ALL=1 ;;
        -h|--help) awk 'NR > 2 && /^# -+$/ { exit } NR > 2 { sub(/^# ?/, ""); print }' "$0"; exit 0 ;;
        *) echo "Unknown option: $1 (try --help)" >&2; exit 1 ;;
    esac
    shift
done
case "$JOBS" in ''|*[!0-9]*|0) echo "--jobs must be a positive number" >&2; exit 1 ;; esac

command -v aws >/dev/null || { echo "The AWS CLI is not installed (https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html)" >&2; exit 1; }
command -v jq >/dev/null || { echo "jq is not installed (https://jqlang.org/download/)" >&2; exit 1; }
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

# warn <region> <what> <stderr file>: a call that failed (Regions without the service are skipped quietly)
warn() {
    grep -q 'Could not connect to the endpoint URL' "$3" && return 0
    printf '%s\t%s\t%s\n' "$1" "$2" "$(grep -v '^$' "$3" | head -n 1)" >>"$WORK/warnings"
}

# call <region> <service> <operation> [args...]: the call's JSON; a failed call is warned about and prints nothing
call() {
    local region=$1 service=$2 operation=$3 err
    shift 3
    err=$(mktemp "$WORK/err.XXXXXX")
    aws --profile "$PROFILE" --region "$region" --output json "$service" "$operation" "$@" 2>"$err" && return 0
    warn "$region" "$service $operation" "$err"
    return 0
}

# optional <error code> <region> <service> <operation> [args...]: like call, but prints null when the call fails with
# that error code (a setting that was never configured: no bucket policy, no password policy...)
optional() {
    local code=$1 region=$2 service=$3 operation=$4 err
    shift 4
    err=$(mktemp "$WORK/err.XXXXXX")
    aws --profile "$PROFILE" --region "$region" --output json "$service" "$operation" "$@" 2>"$err" && return 0
    if grep -q "($code)" "$err"; then echo null; else warn "$region" "$service $operation" "$err"; fi
    return 0
}

# throttle: wait until fewer than $JOBS of this shell's background jobs are running
throttle() {
    while [ "$(jobs -rp | wc -l)" -ge "$JOBS" ]; do wait -n 2>/dev/null || true; done
}

if ! IDENTITY=$(aws --profile "$PROFILE" --region "${AWS_REGION:-$(aws configure get region --profile "$PROFILE" 2>/dev/null || echo us-east-1)}" \
        --output json sts get-caller-identity 2>"$WORK/err"); then
    echo "Cannot use profile '$PROFILE':" >&2; sed 's/^/  /' "$WORK/err" >&2
    echo "(SSO profiles: aws sso login --profile $PROFILE)" >&2
    exit 1
fi
ACCOUNT=$(printf '%s' "$IDENTITY" | jq -r .Account)
CALLER=$(printf '%s' "$IDENTITY" | jq -r .Arn)
PARTITION=$(printf '%s' "$CALLER" | cut -d: -f2)
HOME_REGION=${AWS_REGION:-$(aws configure get region --profile "$PROFILE" 2>/dev/null || true)}
case "$PARTITION" in
    aws-cn) HOME_REGION=${HOME_REGION:-cn-north-1} ;;
    aws-us-gov) HOME_REGION=${HOME_REGION:-us-gov-west-1} ;;
    *) HOME_REGION=${HOME_REGION:-us-east-1} ;;
esac

if [ -n "$REGIONS" ]; then
    REGIONS=$(printf '%s' "$REGIONS" | tr ',' ' ')
elif ! REGIONS=$(aws --profile "$PROFILE" --region "$HOME_REGION" --output json ec2 describe-regions 2>"$WORK/err" | jq -r '.Regions[].RegionName'); then
    echo "Cannot list the account's Regions (use --regions):" >&2; sed 's/^/  /' "$WORK/err" >&2; exit 1
fi
# shellcheck disable=SC2086 # one Region per word
REGIONS=$(printf '%s\n' $REGIONS | sort -u | tr '\n' ' ')

echo "Profile $PROFILE: account $ACCOUNT as $CALLER" >&2
echo "Regions: $REGIONS" >&2

# jq helpers shared by every collector. Rows are {type, region, arn, name, ip, details}; details leave out what is unknown.
JQ_LIB='
def tag($k): [(.Tags // .tags // [])[] | select((.Key // .key) == $k) | (.Value // .value)] | .[0];
def arn($service; $resource): "arn:\($P):\($service):\($r):\($A):\($resource)";
def row($type; $arn; $name; $details): {type: $type, region: $r, arn: $arn, name: ($name // ""), ip: "", details: ($details | with_entries(select(.value != null)))};
def day: if . == null or . == "" then null else (tostring | .[0:10]) end;
def ids($list): [$list[]? | select(. != null)];
'

# emit <region> <jq program> [jq args...]: run a program with the helpers on the input, one compact row per line
emit() {
    local r=$1 program=$2 err
    shift 2
    err=$(mktemp "$WORK/err.XXXXXX")
    jq -c --arg P "$PARTITION" --arg A "$ACCOUNT" --arg r "$r" --arg all "$ALL" "$@" "$JQ_LIB $program" 2>"$err" || warn "$r" "jq (unexpected response)" "$err"
}

# ---- Regional resources ------------------------------------------------------------------------------------------

collect_network() {
    local r=$1 d=$2
    # Network interfaces tell which VPCs and security groups are in use; without them nothing is left out
    call "$r" ec2 describe-network-interfaces >"$d/enis"
    call "$r" ec2 describe-vpcs >"$d/vpcs"
    call "$r" ec2 describe-flow-logs >"$d/flowlogs"
    jq -n --slurpfile e "$d/enis" --slurpfile v "$d/vpcs" --arg all "$ALL" '
        ($e | length > 0) as $known
        | ([$e[0].NetworkInterfaces[]?.VpcId] | unique) as $vpcs
        | (if $known then [$v[0].Vpcs[]? | select(.IsDefault and (.VpcId | IN($vpcs[]) | not)) | .VpcId] else [] end) as $unused
        | {known: $known, vpcs: $vpcs, groups: ([$e[0].NetworkInterfaces[]?.Groups[]?.GroupId] | unique),
           unused_default: $unused, skip: (if $all == "1" then [] else $unused end)}
    ' >"$d/usage"

    emit "$r" '
        .Vpcs[]? | select(.VpcId | IN($u[0].skip[]) | not) | .VpcId as $id
        | row("ec2:vpc"; arn("ec2"; "vpc/\(.VpcId)"); tag("Name"); {
            default: .IsDefault, cidr: .CidrBlock,
            in_use: (if $u[0].known then (.VpcId | IN($u[0].vpcs[])) else null end),
            flow_logs: (if ($f | length) > 0 then any($f[0].FlowLogs[]?; .ResourceId == $id) else null end)})
    ' --slurpfile u "$d/usage" --slurpfile f "$d/flowlogs" <"$d/vpcs"

    call "$r" ec2 describe-security-groups | emit "$r" '
        def ports: if .IpProtocol == "-1" then "all" elif .FromPort == .ToPort then "\(.IpProtocol)/\(.FromPort)" else "\(.IpProtocol)/\(.FromPort)-\(.ToPort)" end;
        .SecurityGroups[]? | select(.VpcId | IN($u[0].skip[]) | not)
        # A default security group without rules lets nothing in or out: plumbing, unless --all
        | select($all == "1" or .GroupName != "default" or ((.IpPermissions | length) + (.IpPermissionsEgress | length)) > 0)
        | row("ec2:security-group"; arn("ec2"; "security-group/\(.GroupId)"); (tag("Name") // .GroupName); {
            vpc: .VpcId, default: (.GroupName == "default"), description: .Description,
            in_use: (if $u[0].known then (.GroupId | IN($u[0].groups[])) else null end),
            rules: ((.IpPermissions // [] | length) + (.IpPermissionsEgress // [] | length)),
            open_ingress: [.IpPermissions[]? | select(any(.IpRanges[]?; .CidrIp == "0.0.0.0/0") or any(.Ipv6Ranges[]?; .CidrIpv6 == "::/0")) | ports] | unique})
    ' --slurpfile u "$d/usage"

    call "$r" ec2 describe-network-acls | emit "$r" '
        .NetworkAcls[]? | select(.VpcId | IN($u[0].skip[]) | not) | select($all == "1" or (.IsDefault | not))
        | row("ec2:network-acl"; arn("ec2"; "network-acl/\(.NetworkAclId)"); tag("Name"); {vpc: .VpcId, default: .IsDefault})
    ' --slurpfile u "$d/usage"

    call "$r" ec2 describe-internet-gateways | emit "$r" '
        .InternetGateways[]? | select([.Attachments[]?.VpcId] | any(IN($u[0].skip[])) | not)
        | row("ec2:internet-gateway"; arn("ec2"; "internet-gateway/\(.InternetGatewayId)"); tag("Name"); {vpc: .Attachments[0]?.VpcId})
    ' --slurpfile u "$d/usage"

    call "$r" ec2 describe-nat-gateways | emit "$r" '
        .NatGateways[]? | select(.State != "deleted")
        | row("ec2:natgateway"; arn("ec2"; "natgateway/\(.NatGatewayId)"); tag("Name"); {vpc: .VpcId, state: .State})
        + {ip: (.NatGatewayAddresses[0]?.PublicIp // "")}'
    call "$r" ec2 describe-transit-gateways | emit "$r" '
        .TransitGateways[]? | select(.State != "deleted") | row("ec2:transit-gateway"; .TransitGatewayArn; tag("Name"); {state: .State})'
    call "$r" ec2 describe-vpn-connections | emit "$r" '
        .VpnConnections[]? | select(.State != "deleted")
        | row("ec2:vpn-connection"; arn("ec2"; "vpn-connection/\(.VpnConnectionId)"); tag("Name"); {
            state: .State, tunnels_up: (if .VgwTelemetry then "\([.VgwTelemetry[] | select(.Status == "UP")] | length) of \(.VgwTelemetry | length)" else null end)})'
    call "$r" ec2 describe-vpn-gateways | emit "$r" '
        .VpnGateways[]? | select(.State != "deleted")
        | row("ec2:vpn-gateway"; arn("ec2"; "vpn-gateway/\(.VpnGatewayId)"); tag("Name"); {state: .State, vpc: .VpcAttachments[0]?.VpcId})'
    call "$r" ec2 describe-addresses | emit "$r" '
        .Addresses[]? | row("ec2:elastic-ip"; arn("ec2"; "elastic-ip/\(.AllocationId)"); tag("Name"); {
            associated: (.AssociationId != null), instance: .InstanceId}) + {ip: (.PublicIp // "")}'

    # Account facts from this Region
    jq -n -c --arg r "$r" --slurpfile u "$d/usage" '{region: $r, unused_default_vpc: ($u[0].unused_default | length > 0)}' >"$d/account.network"
}

collect_compute() {
    local r=$1 d=$2 name fn
    call "$r" ssm describe-instance-information >"$d/ssm"
    call "$r" ec2 describe-instances | emit "$r" '
        ([$s[0].InstanceInformationList[]? | {key: .InstanceId, value: .}] | from_entries) as $ssm
        | .Reservations[]?.Instances[]? | select(.State.Name | IN("pending", "running", "stopping", "stopped", "shutting-down"))
        | $ssm[.InstanceId] as $m
        | row("ec2:instance"; arn("ec2"; "instance/\(.InstanceId)"); tag("Name"); {
            state: .State.Name, instance_type: .InstanceType, platform: .PlatformDetails,
            os: $m.PlatformName, os_version: $m.PlatformVersion, ssm_managed: (if ($s | length) > 0 then $m != null else null end),
            imds: .MetadataOptions.HttpTokens, iam_profile: (.IamInstanceProfile.Arn // null | if . then split("/") | last else null end),
            security_groups: [.SecurityGroups[]?.GroupId], vpc: .VpcId, private_ip: .PrivateIpAddress, public_dns: (.PublicDnsName | if . == "" then null else . end),
            launched: (.LaunchTime | day)}) + {ip: (.PublicIpAddress // "")}
    ' --slurpfile s "$d/ssm"

    call "$r" ec2 describe-volumes | emit "$r" '
        .Volumes[]? | row("ec2:volume"; arn("ec2"; "volume/\(.VolumeId)"); tag("Name"); {
            state: .State, size_gb: .Size, volume_type: .VolumeType, encrypted: .Encrypted, kms_key: .KmsKeyId,
            attached_to: [.Attachments[]?.InstanceId]})'

    call "$r" eks list-clusters | jq -r '.clusters[]?' | while read -r name; do
        call "$r" eks describe-cluster --name "$name" >"$d/detail"
        emit "$r" '
            ($x[0].cluster // {}) | row("eks:cluster"; arn("eks"; "cluster/\($n)"); $n; {
                version: .version, status: .status,
                endpoint_public: .resourcesVpcConfig.endpointPublicAccess, endpoint_private: .resourcesVpcConfig.endpointPrivateAccess,
                public_access_cidrs: (if .resourcesVpcConfig.endpointPublicAccess then .resourcesVpcConfig.publicAccessCidrs else null end),
                secrets_encrypted: ((.encryptionConfig // []) | length > 0),
                logging: (if .logging then [.logging.clusterLogging[]? | select(.enabled) | .types[]] else null end)})
        ' -n --arg n "$name" --slurpfile x "$d/detail"
    done
    call "$r" ecs list-clusters | emit "$r" '.clusterArns[]? | row("ecs:cluster"; .; null; {})'

    call "$r" lambda list-functions | jq -c '.Functions[]?' | while read -r fn; do
        name=$(printf '%s' "$fn" | jq -r .FunctionName)
        call "$r" lambda list-function-url-configs --function-name "$name" >"$d/lambda-url"
        printf '%s' "$fn" | emit "$r" '
            ($u[0].FunctionUrlConfigs[0]? // null) as $url
            | row("lambda:function"; .FunctionArn; .FunctionName; {
                runtime: (.Runtime // (if .PackageType == "Image" then "container image" else null end)),
                last_modified: (.LastModified | day), role: (.Role | split("/") | last),
                url: $url.FunctionUrl, url_auth: $url.AuthType})
        ' --slurpfile u "$d/lambda-url"
    done

    call "$r" ecr describe-repositories | emit "$r" '
        .repositories[]? | row("ecr:repository"; .repositoryArn; .repositoryName; {
            scan_on_push: .imageScanningConfiguration.scanOnPush, tag_mutability: .imageTagMutability,
            encryption: .encryptionConfiguration.encryptionType})'
}

collect_edge() {
    local r=$1 d=$2 lb arn
    call "$r" elbv2 describe-load-balancers | jq -c '.LoadBalancers[]?' | while read -r lb; do
        arn=$(printf '%s' "$lb" | jq -r .LoadBalancerArn)
        call "$r" elbv2 describe-listeners --load-balancer-arn "$arn" >"$d/listeners"
        printf '%s' "$lb" | emit "$r" '
            (.LoadBalancerArn | capture(":loadbalancer/(?<k>app|net|gwy)/").k // "") as $kind
            | row("elasticloadbalancing:loadbalancer" + (if $kind != "" then "/" + $kind else "" end); .LoadBalancerArn; .LoadBalancerName; {
                lb_type: .Type, scheme: .Scheme, dns_name: .DNSName, state: .State.Code, vpc: .VpcId,
                listeners: [$l[0].Listeners[]? | "\(.Protocol):\(.Port)" + (if any(.DefaultActions[]?; .Type == "redirect") then " redirect" else "" end)],
                ssl_policies: ([$l[0].Listeners[]? | .SslPolicy // empty] | unique)})
        ' --slurpfile l "$d/listeners"
    done
    call "$r" elb describe-load-balancers | emit "$r" '
        .LoadBalancerDescriptions[]? | row("elasticloadbalancing:loadbalancer"; arn("elasticloadbalancing"; "loadbalancer/\(.LoadBalancerName)"); .LoadBalancerName; {
            lb_type: "classic", scheme: .Scheme, dns_name: .DNSName, vpc: .VPCId,
            listeners: [.ListenerDescriptions[]?.Listener | "\(.Protocol):\(.LoadBalancerPort)"]})'

    call "$r" apigateway get-rest-apis | emit "$r" '
        .items[]? | row("apigateway:restapis"; "arn:\($P):apigateway:\($r)::/restapis/\(.id)"; .name; {
            endpoint_type: (.endpointConfiguration.types // [] | join(", ")), api_key_source: .apiKeySource})'
    call "$r" apigatewayv2 get-apis | emit "$r" '
        .Items[]? | row("apigateway:apis"; "arn:\($P):apigateway:\($r)::/apis/\(.ApiId)"; .Name; {
            protocol: .ProtocolType, endpoint: .ApiEndpoint, default_endpoint: ((.DisableExecuteApiEndpoint // false) | not)})'

    call "$r" acm list-certificates | emit "$r" '
        .CertificateSummaryList[]? | row("acm:certificate"; .CertificateArn; .DomainName; {
            status: .Status, cert_type: .Type, expires: (.NotAfter | day), in_use: .InUse, renewal: .RenewalEligibility})'
    call "$r" wafv2 list-web-acls --scope REGIONAL | emit "$r" '.WebACLs[]? | row("wafv2:regional/webacl"; .ARN; .Name; {scope: "regional"})'
}

collect_storage() {
    local r=$1
    call "$r" efs describe-file-systems | emit "$r" '
        .FileSystems[]? | row("elasticfilesystem:file-system"; .FileSystemArn; .Name; {
            state: .LifeCycleState, encrypted: .Encrypted, kms_key: .KmsKeyId, size_gb: ((.SizeInBytes.Value // 0) / 1073741824 | floor)})'
    call "$r" fsx describe-file-systems | emit "$r" '
        .FileSystems[]? | row("fsx:file-system"; .ResourceARN; tag("Name"); {
            fs_type: .FileSystemType, state: .Lifecycle, encrypted: true, kms_key: .KmsKeyId, size_gb: .StorageCapacity})'
    call "$r" backup list-backup-vaults | emit "$r" '
        .BackupVaultList[]? | row("backup:backup-vault"; .BackupVaultArn; .BackupVaultName; {
            encrypted: (.EncryptionKeyArn != null), kms_key: .EncryptionKeyArn, recovery_points: .NumberOfRecoveryPoints, locked: (.Locked // false)})'
}

collect_data() {
    local r=$1 d=$2 name
    call "$r" rds describe-db-instances | emit "$r" '
        .DBInstances[]? | row("rds:db"; .DBInstanceArn; .DBInstanceIdentifier; {
            engine: .Engine, engine_version: .EngineVersion, instance_class: .DBInstanceClass, status: .DBInstanceStatus,
            publicly_accessible: .PubliclyAccessible, encrypted: .StorageEncrypted, kms_key: .KmsKeyId, multi_az: .MultiAZ,
            backup_retention_days: .BackupRetentionPeriod, deletion_protection: .DeletionProtection,
            iam_auth: .IAMDatabaseAuthenticationEnabled, auto_minor_upgrade: .AutoMinorVersionUpgrade, cluster: .DBClusterIdentifier,
            endpoint: (.Endpoint.Address // null)})'
    call "$r" rds describe-db-clusters | emit "$r" '
        .DBClusters[]? | row("rds:cluster"; .DBClusterArn; .DBClusterIdentifier; {
            engine: .Engine, engine_version: .EngineVersion, status: .Status,
            publicly_accessible: .PubliclyAccessible, encrypted: .StorageEncrypted, kms_key: .KmsKeyId, multi_az: .MultiAZ,
            backup_retention_days: .BackupRetentionPeriod, deletion_protection: .DeletionProtection,
            iam_auth: .IAMDatabaseAuthenticationEnabled, endpoint: .Endpoint})'

    call "$r" dynamodb list-tables | jq -r '.TableNames[]?' | while read -r name; do
        call "$r" dynamodb describe-continuous-backups --table-name "$name" >"$d/detail"
        emit "$r" '
            row("dynamodb:table"; arn("dynamodb"; "table/\($t)"); $t; {
                encrypted: true, pitr: (($x[0].ContinuousBackupsDescription.PointInTimeRecoveryDescription.PointInTimeRecoveryStatus // null) | if . then . == "ENABLED" else null end)})
        ' -n --arg t "$name" --slurpfile x "$d/detail"
    done

    call "$r" opensearch list-domain-names | jq -r '.DomainNames[]?.DomainName' | while read -r name; do
        call "$r" opensearch describe-domain --domain-name "$name" >"$d/detail"
        emit "$r" '
            ($x[0].DomainStatus // {}) | row("es:domain"; arn("es"; "domain/\($n)"); $n; {
                engine_version: .EngineVersion, in_vpc: (.VPCOptions.VPCId != null),
                encrypted: .EncryptionAtRestOptions.Enabled, node_to_node_encryption: .NodeToNodeEncryptionOptions.Enabled,
                enforce_https: .DomainEndpointOptions.EnforceHTTPS, tls_policy: .DomainEndpointOptions.TLSSecurityPolicy,
                fine_grained_access: .AdvancedSecurityOptions.Enabled,
                endpoint: (.Endpoint // .Endpoints.vpc // null)})
        ' -n --arg n "$name" --slurpfile x "$d/detail"
    done

    call "$r" redshift describe-clusters | emit "$r" '
        .Clusters[]? | row("redshift:cluster"; arn("redshift"; "cluster:\(.ClusterIdentifier)"); .ClusterIdentifier; {
            node_type: .NodeType, version: .ClusterVersion, status: .ClusterStatus,
            publicly_accessible: .PubliclyAccessible, encrypted: .Encrypted, kms_key: .KmsKeyId,
            endpoint: (.Endpoint.Address // null)})'
    call "$r" elasticache describe-cache-clusters | emit "$r" '
        .CacheClusters[]? | select(.ReplicationGroupId == null or $all == "1")
        | row("elasticache:cluster"; .ARN; .CacheClusterId; {
            engine: .Engine, engine_version: .EngineVersion, node_type: .CacheNodeType, status: .CacheClusterStatus,
            encrypted: (.AtRestEncryptionEnabled // false), in_transit_encryption: (.TransitEncryptionEnabled // false), auth_token: (.AuthTokenEnabled // false)})'
    call "$r" elasticache describe-replication-groups | emit "$r" '
        .ReplicationGroups[]? | row("elasticache:replicationgroup"; .ARN; .ReplicationGroupId; {
            engine: .Engine, node_type: .CacheNodeType, status: .Status, multi_az: (.MultiAZ == "enabled"),
            encrypted: (.AtRestEncryptionEnabled // false), in_transit_encryption: (.TransitEncryptionEnabled // false), auth_token: (.AuthTokenEnabled // false)})'

    call "$r" sqs list-queues | jq -r '.QueueUrls[]?' | while read -r name; do
        call "$r" sqs get-queue-attributes --queue-url "$name" --attribute-names All >"$d/detail"
        emit "$r" '
            ($q | split("/") | last) as $n | ($x[0].Attributes // null) as $a
            | row("sqs:queue"; arn("sqs"; $n); $n; {
                encrypted: (if $a then ($a.SqsManagedSseEnabled // "false") == "true" or $a.KmsMasterKeyId != null else null end), kms_key: $a.KmsMasterKeyId})
        ' -n --arg q "$name" --slurpfile x "$d/detail"
    done
    call "$r" sns list-topics | jq -r '.Topics[]?.TopicArn' | while read -r name; do
        call "$r" sns get-topic-attributes --topic-arn "$name" >"$d/detail"
        emit "$r" '
            ($x[0].Attributes // null) as $a
            | row("sns:topic"; $t; null; {encrypted: (if $a then $a.KmsMasterKeyId != null else null end), kms_key: $a.KmsMasterKeyId,
                subscriptions: ($a.SubscriptionsConfirmed // null | if . then tonumber else null end)})
        ' -n --arg t "$name" --slurpfile x "$d/detail"
    done
    call "$r" kinesis list-streams | jq -r '.StreamSummaries[]?.StreamName' | while read -r name; do
        call "$r" kinesis describe-stream-summary --stream-name "$name" >"$d/detail"
        emit "$r" '
            ($x[0].StreamDescriptionSummary // null) as $s
            | row("kinesis:stream"; arn("kinesis"; "stream/\($n)"); $n; {
                status: $s.StreamStatus, encrypted: (if $s then $s.EncryptionType == "KMS" else null end), kms_key: $s.KeyId, retention_hours: $s.RetentionPeriodHours})
        ' -n --arg n "$name" --slurpfile x "$d/detail"
    done
    call "$r" kafka list-clusters-v2 | emit "$r" '
        .ClusterInfoList[]? | row("kafka:cluster"; .ClusterArn; .ClusterName; {
            cluster_type: .ClusterType, state: .State, kafka_version: .Provisioned.CurrentBrokerSoftwareInfo.KafkaVersion, encrypted: true,
            in_transit_encryption: (.Provisioned.EncryptionInfo.EncryptionInTransit.ClientBroker // null),
            public_access: (if .Provisioned then ((.Provisioned.BrokerNodeGroupInfo.ConnectivityInfo.PublicAccess.Type // "DISABLED") != "DISABLED") else null end)})'
}

collect_identity() {
    local r=$1 d=$2 key id
    # KMS: customer managed keys only (AWS managed keys have alias/aws/...), named after their first alias
    call "$r" kms list-aliases >"$d/kms-aliases"
    call "$r" kms list-keys | jq -c '.Keys[]?' | while read -r key; do
        id=$(printf '%s' "$key" | jq -r .KeyId)
        jq -e --arg id "$id" 'any(.Aliases[]?; .TargetKeyId == $id and (.AliasName | startswith("alias/aws/")))' "$d/kms-aliases" >/dev/null && continue
        call "$r" kms describe-key --key-id "$id" >"$d/kms-key"
        jq -e '.KeyMetadata.KeyManager == "AWS"' "$d/kms-key" >/dev/null 2>&1 && continue
        if jq -e '(.KeyMetadata.KeySpec // "SYMMETRIC_DEFAULT") == "SYMMETRIC_DEFAULT" and .KeyMetadata.KeyState == "Enabled"' "$d/kms-key" >/dev/null 2>&1; then
            call "$r" kms get-key-rotation-status --key-id "$id" >"$d/kms-rotation"
        else
            echo null >"$d/kms-rotation"
        fi
        printf '%s' "$key" | emit "$r" '
            .KeyId as $id | [$al[0].Aliases[]? | select(.TargetKeyId == $id) | .AliasName[6:]] as $names
            | row("kms:key"; .KeyArn; $names[0]; {
                key_state: $k[0].KeyMetadata.KeyState, key_spec: $k[0].KeyMetadata.KeySpec, rotation: $rot[0].KeyRotationEnabled?,
                deletion_date: ($k[0].KeyMetadata.DeletionDate | day), aliases: (if ($names | length) > 1 then $names else null end)})
        ' --slurpfile al "$d/kms-aliases" --slurpfile k "$d/kms-key" --slurpfile rot "$d/kms-rotation"
    done
    call "$r" secretsmanager list-secrets | emit "$r" '
        .SecretList[]? | row("secretsmanager:secret"; .ARN; .Name; {
            rotation: (.RotationEnabled // false), last_rotated: (.LastRotatedDate | day), last_accessed: (.LastAccessedDate | day),
            last_changed: (.LastChangedDate | day), kms_key: .KmsKeyId})'
    call "$r" codecommit list-repositories | emit "$r" '
        .repositories[]? | row("codecommit:repository"; arn("codecommit"; .repositoryName); .repositoryName; {})'

    # Account facts from this Region
    call "$r" ec2 get-ebs-encryption-by-default >"$d/ebs-default"
    call "$r" guardduty list-detectors >"$d/guardduty"
    jq -n -c --arg r "$r" --slurpfile e "$d/ebs-default" --slurpfile g "$d/guardduty" '{region: $r}
        + (if ($e | length) > 0 then {ebs_encryption: $e[0].EbsEncryptionByDefault} else {} end)
        + (if ($g | length) > 0 then {guardduty: ($g[0].DetectorIds | length > 0)} else {} end)' >"$d/account.identity"
}

collect_region() {
    local r=$1 d="$WORK/$1"
    mkdir -p "$d"
    collect_network "$r" "$d"
    collect_compute "$r" "$d"
    collect_edge "$r" "$d"
    collect_storage "$r"
    collect_data "$r" "$d"
    collect_identity "$r" "$d"
}

# ---- Global services ---------------------------------------------------------------------------------------------

# bucket <name> <Region or ""> <account Block Public Access JSON file>: one S3 bucket with its settings
bucket() {
    local b=$1 region=${2:-$HOME_REGION} d
    d=$(mktemp -d "$WORK/bucket.XXXXXX")
    optional NoSuchPublicAccessBlockConfiguration "$region" s3api get-public-access-block --bucket "$b" >"$d/bpa"
    optional NoSuchBucketPolicy "$region" s3api get-bucket-policy-status --bucket "$b" >"$d/policy"
    call "$region" s3api get-bucket-acl --bucket "$b" >"$d/acl"
    optional ServerSideEncryptionConfigurationNotFoundError "$region" s3api get-bucket-encryption --bucket "$b" >"$d/encryption"
    call "$region" s3api get-bucket-versioning --bucket "$b" >"$d/versioning"
    jq -n -c --arg P "$PARTITION" --arg A "$ACCOUNT" --arg r global --arg b "$b" --arg region "${2:-}" \
        --slurpfile bpa "$d/bpa" --slurpfile acct "$3" --slurpfile pol "$d/policy" --slurpfile acl "$d/acl" \
        --slurpfile enc "$d/encryption" --slurpfile ver "$d/versioning" "$JQ_LIB"'
        def settings: ["BlockPublicAcls", "IgnorePublicAcls", "BlockPublicPolicy", "RestrictPublicBuckets"];
        # A setting is on when the bucket or the account turns it on; unknown when neither could be read
        (if ($bpa | length) == 0 and ($acct | length) == 0 then null
         else [settings[] as $s | select((($bpa[0].PublicAccessBlockConfiguration[$s]?) // false) or (($acct[0].PublicAccessBlockConfiguration[$s]?) // false) | not) | $s]
         end) as $off
        | ($enc[0].ServerSideEncryptionConfiguration.Rules[0]?.ApplyServerSideEncryptionByDefault // null) as $sse
        | row("s3:bucket"; "arn:\($P):s3:::\($b)"; $b; {
            block_public_access: (if $off == null then null elif ($off | length) == 0 then "all" elif ($off | length) == 4 then "none" else "partial" end),
            block_public_access_off: (if $off == null or ($off | length) == 0 then null else $off end),
            policy_public: (if ($pol | length) == 0 then null else ($pol[0].PolicyStatus.IsPublic? // false) end),
            acl_public: (if ($acl | length) == 0 then null else any($acl[0].Grants[]?; .Grantee.URI // "" | test("/global/(AllUsers|AuthenticatedUsers)$")) end),
            encryption: (if ($enc | length) == 0 then null else ($sse.SSEAlgorithm // "none") end),
            encrypted: (if ($enc | length) == 0 then null else $sse != null end), kms_key: $sse.KMSMasterKeyID,
            versioning: (if ($ver | length) == 0 then null else ($ver[0].Status // "Off") end),
            mfa_delete: (if ($ver | length) == 0 then null else ($ver[0].MFADelete // "Disabled") == "Enabled" end)})
        | .region = (if $region == "" then "global" else $region end)'
}

collect_global() {
    local r=$HOME_REGION d="$WORK/global" i=0
    mkdir -p "$d/buckets"

    # S3: the account's Block Public Access applies to every bucket
    optional NoSuchPublicAccessBlockConfiguration "$r" s3control get-public-access-block --account-id "$ACCOUNT" >"$d/s3-account-bpa"
    call "$r" s3api list-buckets | jq -r '.Buckets[]? | [.Name, (.BucketRegion // "")] | @tsv' >"$d/bucket-list"
    while IFS=$'\t' read -r name region; do
        throttle
        i=$((i + 1))
        bucket "$name" "$region" "$d/s3-account-bpa" >"$d/buckets/$i" &
    done <"$d/bucket-list"
    wait
    cat "$d/buckets"/* 2>/dev/null || true

    # IAM: roles and users with their policies, and the credential report for passwords, MFA and access keys
    call "$r" iam get-account-authorization-details --filter Role User Group >"$d/iam"
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        call "$r" iam generate-credential-report >"$d/report-state"
        jq -e '.State == "COMPLETE"' "$d/report-state" >/dev/null 2>&1 && break
        sleep 2
    done
    call "$r" iam get-credential-report | jq -r '.Content // empty' | base64 -d >"$d/credentials" 2>/dev/null || true
    emit global '
        def cdate: if . == null or . == "N/A" or . == "no_information" or . == "not_supported" then null else .[0:10] end;
        def admin: any(.AttachedManagedPolicies[]?; .PolicyArn | endswith(":policy/AdministratorAccess"));
        def principals: if . == "*" then ["*"] elif type == "object" then [to_entries[] | .value | if type == "array" then .[] else . end] else [] end;
        def allows: [.AssumeRolePolicyDocument.Statement[]? | select(.Effect == "Allow")];
        ([.GroupDetailList[]? | select(admin) | .GroupName]) as $adminGroups
        | ($cred | split("\n") | map(select(length > 0) | split(",")) | .[1:] | map({key: .[0], value: .}) | from_entries) as $report
        | (.RoleDetailList[]? | select($all == "1" or (.Path | test("^/(aws-service-role|aws-reserved)/") | not))
            | row("iam:role"; .Arn; .RoleName; {
                path: (if .Path != "/" then .Path else null end), created: (.CreateDate | day), last_used: (.RoleLastUsed.LastUsedDate | day),
                trusted: ([allows[] | .Principal | principals[]] | unique),
                trusted_unconditional: ([allows[] | select(.Condition == null) | .Principal | principals[]] | unique),
                admin: admin, max_session_hours: (.MaxSessionDuration // 3600 | . / 3600 | floor)})),
        (.UserDetailList[]? | $report[.UserName] as $c
            | row("iam:user"; .Arn; .UserName; {
                created: (.CreateDate | day), groups: (if (.GroupList | length) > 0 then .GroupList else null end),
                admin: (admin or any(.GroupList[]?; IN($adminGroups[]))),
                console: (if $c then $c[3] == "true" else null end), password_last_used: (if $c then $c[4] | cdate else null end),
                mfa: (if $c then $c[7] == "true" else null end),
                access_keys: (if $c then [($c[8:11], $c[13:16]) | select(.[0] == "true") | {last_rotated: (.[1] | cdate), last_used: (.[2] | cdate)}] else null end)}))
    ' --rawfile cred "$d/credentials" <"$d/iam"

    call "$r" cloudfront list-distributions | emit global '
        .DistributionList.Items[]? | row("cloudfront:distribution"; .ARN; (if (.Comment // "") != "" then .Comment else .DomainName end); {
            enabled: .Enabled, domain: .DomainName, aliases: (.Aliases.Items // []),
            viewer_protocol: .DefaultCacheBehavior.ViewerProtocolPolicy,
            min_tls: (if .ViewerCertificate.CloudFrontDefaultCertificate then null else .ViewerCertificate.MinimumProtocolVersion end),
            waf: ((.WebACLId // "") != "")})'
    call "$r" route53 list-hosted-zones | emit global '
        .HostedZones[]? | row("route53:hostedzone"; "arn:\($P):route53:::hostedzone/\(.Id | split("/") | last)"; (.Name | sub("\\.$"; "")); {
            private: .Config.PrivateZone, records: .ResourceRecordSetCount})'
    if [ "$PARTITION" = aws ]; then
        call us-east-1 wafv2 list-web-acls --scope CLOUDFRONT | emit global '
            .WebACLs[]? | row("wafv2:global/webacl"; .ARN; .Name; {scope: "CloudFront"})'
    fi

    # Account facts
    call "$r" iam list-account-aliases >"$d/alias"
    call "$r" iam get-account-summary >"$d/summary"
    optional NoSuchEntity "$r" iam get-account-password-policy >"$d/password-policy"
    call "$r" cloudtrail describe-trails >"$d/trails"
    jq -r '.trailList[]? | [.TrailARN, .HomeRegion] | @tsv' "$d/trails" | while IFS=$'\t' read -r trail home; do
        call "${home:-$r}" cloudtrail get-trail-status --name "$trail" | jq -c --arg t "$trail" '{trail: $t, logging: .IsLogging}'
    done >"$d/trail-status"
}

# account_row: one row with the account's own settings, from the global and regional facts
account_row() {
    local d="$WORK/global"
    cat "$WORK"/*/account.* 2>/dev/null | jq -s -c '.' >"$d/regional"
    jq -n -c --arg A "$ACCOUNT" --arg P "$PARTITION" \
        --slurpfile alias "$d/alias" --slurpfile summary "$d/summary" --slurpfile pp "$d/password-policy" \
        --slurpfile trails "$d/trails" --slurpfile status "$d/trail-status" --slurpfile bpa "$d/s3-account-bpa" \
        --slurpfile regional "$d/regional" '
        def regions($key; $value): [$regional[0] | group_by(.region)[] | add | select(has($key) and .[$key] == $value) | .region] | sort;
        def known($key): any($regional[0][]; has($key));
        ([$status[] | {key: .trail, value: .logging}] | from_entries) as $logging
        | [$trails[0].trailList[]? | select($logging[.TrailARN] == true)] as $live
        | ($bpa[0].PublicAccessBlockConfiguration // null) as $b
        | {type: "account", region: "global", arn: "arn:\($P):iam::\($A):root", name: ($alias[0].AccountAliases[0] // ""), ip: "",
           details: ({
            alias: $alias[0].AccountAliases[0],
            root_mfa: (if ($summary | length) > 0 then $summary[0].SummaryMap.AccountMFAEnabled == 1 else null end),
            root_access_keys: (if ($summary | length) > 0 then $summary[0].SummaryMap.AccountAccessKeysPresent == 1 else null end),
            iam_users: $summary[0].SummaryMap.Users,
            password_policy: (if ($pp | length) > 0 then $pp[0] != null else null end),
            password_min_length: $pp[0].PasswordPolicy.MinimumPasswordLength?,
            cloudtrail_trails: (if ($trails | length) > 0 then [$live[] | .Name] else null end),
            cloudtrail_multi_region: (if ($trails | length) > 0 then any($live[]; .IsMultiRegionTrail) else null end),
            s3_block_public_access: (if ($bpa | length) == 0 then null elif $b == null then "none"
                else ([$b[] | select(. == true)] | length) as $n | if $n == 4 then "all" elif $n == 0 then "none" else "partial" end end),
            guardduty_off: (if known("guardduty") then regions("guardduty"; false) else null end),
            ebs_encryption_off: (if known("ebs_encryption") then regions("ebs_encryption"; false) else null end),
            unused_default_vpcs: (if known("unused_default_vpc") then regions("unused_default_vpc"; true) else null end),
            regions: ([$regional[0][].region] | unique | length)
           } | with_entries(select(.value != null)))}'
}

# Global services alongside the Regions, $JOBS Regions at a time
collect_global >"$WORK/rows.global" &
for region in $REGIONS; do
    throttle
    collect_region "$region" >"$WORK/rows.$region" &
done
wait
account_row >"$WORK/rows.account"

OUTPUT=${OUTPUT:-aws-$PROFILE-$ACCOUNT-$(date +%Y%m%d).csv}
umask 077
cat "$WORK"/rows.* | jq -s -r --arg account "$ACCOUNT" '
    def csv: tostring | if test("[\",\r\n]") then "\"" + gsub("\""; "\"\"") + "\"" else . end;
    "ARN,Resource type,Region,Account ID,Name,Public IP,Details",
    (unique_by(.arn) | sort_by(.type, .arn)[]
        | [.arn, .type, .region, $account, (.name | sub("\\.$"; "")), .ip, (.details | tojson)] | map(csv) | join(","))
' >"$WORK/out.csv"
mv "$WORK/out.csv" "$OUTPUT"

echo >&2
echo "Resources by type:" >&2
tail -n +2 "$OUTPUT" | cut -d, -f2 | LC_ALL=C sort | uniq -c | sed 's/^ */  /' >&2
echo "Wrote $(($(wc -l <"$OUTPUT") - 1)) rows (resources and the account's settings) to $OUTPUT" >&2
UNUSED=$(jq -r '.details.unused_default_vpcs // [] | join(" ")' "$WORK/rows.account")
if [ -n "$UNUSED" ] && [ "$ALL" = 0 ]; then
    echo "Left out the unused default VPCs (and their security group, network ACL and internet gateway) in: $UNUSED (--all keeps them)" >&2
fi
if [ -s "$WORK/warnings" ]; then
    echo >&2
    echo "Not collected ($(wc -l <"$WORK/warnings") calls failed; missing permissions or the service is not enabled):" >&2
    LC_ALL=C sort "$WORK/warnings" | sed 's/^/  /' >&2
fi
echo >&2
echo "Next: make import-cloud FILE=$OUTPUT PROVIDER=aws AS=<username> DRY_RUN=1" >&2
