<?php

namespace roogul\enquiries\services;

use Craft;
use craft\web\Request;
use roogul\enquiries\models\Form;
use roogul\enquiries\Plugin;
use yii\base\Component;

/**
 * Silent spam checks (honeypot, time trap) and Cloudflare Turnstile.
 */
class Spam extends Component
{
    public const TIMESTAMP_PARAM = 'enq_ts';
    public const TURNSTILE_PARAM = 'cf-turnstile-response';

    /**
     * Signed render time for the time trap.
     */
    public function timestampToken(): string
    {
        return Craft::$app->getSecurity()->hashData((string)time());
    }

    /**
     * Returns why a submission looks like spam, or null if it passes.
     * These fail silently: the bot is shown a normal success response.
     */
    public function check(Form $form, Request $request): ?string
    {
        $settings = Plugin::getInstance()->getSettings();

        $honeypot = $request->getBodyParam($settings->honeypotName);
        if ($honeypot !== null && $honeypot !== '') {
            return 'honeypot';
        }

        if ($settings->minSubmitSeconds > 0) {
            $token = (string)$request->getBodyParam(self::TIMESTAMP_PARAM, '');
            $rendered = $token !== '' ? Craft::$app->getSecurity()->validateData($token) : false;

            if ($rendered === false || !ctype_digit($rendered)) {
                return 'missing-timestamp';
            }

            if (time() - (int)$rendered < $settings->minSubmitSeconds) {
                return 'too-fast';
            }
        }

        return null;
    }

    public function turnstileEnabled(Form $form): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $form->turnstile && $settings->getTurnstileSiteKey() !== '' && $settings->getTurnstileSecretKey() !== '';
    }

    /**
     * Verifies a Turnstile token. Returns an error message, or null if it passed.
     */
    public function verifyTurnstile(Request $request): ?string
    {
        $token = (string)$request->getBodyParam(self::TURNSTILE_PARAM, '');

        if ($token === '') {
            return Craft::t('enquiries-by-roogul', 'Please complete the security check.');
        }

        try {
            $response = Craft::createGuzzleClient(['timeout' => 10])->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'form_params' => [
                    'secret' => Plugin::getInstance()->getSettings()->getTurnstileSecretKey(),
                    'response' => $token,
                    'remoteip' => $request->getUserIP(),
                ],
            ]);
            $result = json_decode((string)$response->getBody(), true);
        } catch (\Throwable $e) {
            // Don't lose a real enquiry because Cloudflare had a blip.
            Craft::warning('Enquiries: Turnstile verification failed to run: ' . $e->getMessage(), __METHOD__);
            return null;
        }

        if (empty($result['success'])) {
            return Craft::t('enquiries-by-roogul', 'The security check failed. Please try again.');
        }

        return null;
    }
}
