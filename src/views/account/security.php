<?php
/**
 * Account › Sign-in security: authenticator app, passkeys and recovery codes.
 * @var yii\web\View $this
 * @var app\models\User $user
 * @var bool $hasTotp
 * @var app\models\UserPasskey[] $passkeys
 * @var int $codesLeft
 * @var bool $required
 * @var string|null $passkeyProblem why passkeys cannot be added on this address
 * @var bool $confirmed the password was confirmed recently
 */

use app\assets\PasskeyAsset;
use app\helpers\Badge;
use yii\helpers\Html;
use yii\helpers\Url;

$this->title = 'Sign-in security';
$this->params['breadcrumbs'][] = $this->title;
$fmt = Yii::$app->formatter;
$enabled = $hasTotp || $passkeys;
if ($passkeyProblem === null) {
    PasskeyAsset::register($this);
}
$post = static fn(string $label, array $route, string $class, ?string $confirm = null) => Html::a($label, $route, array_filter([
    'class' => $class, 'data-method' => 'post', 'data-confirm' => $confirm,
]));
?>
<div class="row g-4">
    <div class="col-lg-8">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Two-step sign-in
                <?= $enabled ? Badge::render('mfa', 'on', 'On') : Badge::render('mfa', $required ? 'missing' : 'off', 'Off') ?>
            </h2>
            <p class="form-text gw-field__hint">
                Two-step sign-in asks for something you have as well as your password: a code from an app on your phone, or a passkey.
                <?= $required ? '<strong>Your administrator requires it.</strong>' : '' ?>
            </p>

            <div class="gw-method">
                <div class="gw-method__body">
                    <h3 class="gw-method__title"><i class="bi bi-phone"></i> Authenticator app</h3>
                    <p class="gw-method__text">
                        <?= $hasTotp
                            ? 'Set up. Each sign-in asks for the 6-digit code the app shows.'
                            : 'Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden or any app that shows 6-digit codes.' ?>
                    </p>
                </div>
                <div class="gw-method__actions">
                    <?php if ($hasTotp): ?>
                        <?= $post('Turn off', ['totp-disable'], 'btn btn-sm btn-outline-danger gw-btn', 'Turn off the authenticator app? Sign-in will no longer ask for its codes.') ?>
                    <?php else: ?>
                        <?= Html::a('Set up', ['totp'], ['class' => 'btn btn-sm btn-primary gw-btn']) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gw-method">
                <div class="gw-method__body">
                    <h3 class="gw-method__title"><i class="bi bi-person-badge"></i> Passkeys</h3>
                    <p class="gw-method__text">
                        A passkey on this computer, your phone or a security key (YubiKey). It signs you in on its own, with your
                        fingerprint, face or PIN, or as the second step after your password.
                    </p>
                    <?php if ($passkeys): ?>
                        <ul class="gw-passkeys">
                            <?php foreach ($passkeys as $passkey): ?>
                                <li>
                                    <span>
                                        <strong><?= Html::encode($passkey->name) ?></strong>
                                        <span class="gw-passkeys__meta">
                                            · added <?= $fmt->asDate($passkey->created_at) ?>
                                            · <?= $passkey->last_used_at ? 'last used ' . $fmt->asDatetime($passkey->last_used_at) : 'not used yet' ?>
                                            <?= $passkey->backed_up ? '· synced' : '' ?>
                                        </span>
                                    </span>
                                    <?= $post('Remove', ['passkey-remove', 'id' => $passkey->id], 'btn btn-sm btn-outline-danger gw-btn',
                                        sprintf('Remove the passkey "%s"? It will no longer sign you in.', $passkey->name)) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($passkeyProblem !== null): ?>
                        <p class="gw-method__text mt-2"><i class="bi bi-info-circle"></i> <?= Html::encode($passkeyProblem) ?></p>
                    <?php else: ?>
                        <div class="row g-2 mt-2 align-items-end" data-gw-passkey-error="#passkey-error">
                            <div class="col-sm-7">
                                <label class="form-label gw-field__label" for="passkey-name">Name for the new passkey</label>
                                <input id="passkey-name" class="form-control" maxlength="64" placeholder="e.g. Work laptop, YubiKey" autocomplete="off">
                            </div>
                            <div class="col-sm-5">
                                <?= Html::button('<i class="bi bi-plus-lg"></i> Add a passkey', [
                                    'class' => 'btn btn-primary gw-btn w-100',
                                    'data-gw-passkey' => 'register',
                                    'data-name-input' => '#passkey-name',
                                    'data-options-url' => Url::to(['passkey-options']),
                                    'data-submit-url' => Url::to(['passkey-add']),
                                ]) ?>
                            </div>
                            <p id="passkey-error" class="gw-login__error col-12" role="alert" hidden></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gw-method">
                <div class="gw-method__body">
                    <h3 class="gw-method__title"><i class="bi bi-life-preserver"></i> Recovery codes</h3>
                    <p class="gw-method__text">
                        <?php if ($enabled): ?>
                            <?= $codesLeft ?> of <?= \app\components\Mfa::RECOVERY_CODES ?> left. Each one signs you in once if you lose your phone or passkeys.
                        <?php else: ?>
                            Made when you set up your first method.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="gw-method__actions">
                    <?php if ($enabled): ?>
                        <?= $post('Make new codes', ['recovery-codes'], 'btn btn-sm btn-outline-secondary gw-btn', 'Make new recovery codes? The old ones stop working.') ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
    <div class="col-lg-4">
        <section class="gw-panel">
            <h2 class="gw-panel__title">Good to know</h2>
            <ul class="gw-method__text ps-3 mb-0">
                <li>Changes here ask for your password if you have not signed in or confirmed it in the last <?= intdiv(\app\components\Mfa::CONFIRM_TTL, 60) ?> minutes.<?= $confirmed ? '' : ' You will be asked for it.' ?></li>
                <li>Keep the recovery codes somewhere safe, such as your password manager.</li>
                <li>If you lose everything, an administrator can reset your two-step sign-in.</li>
            </ul>
        </section>
    </div>
</div>
