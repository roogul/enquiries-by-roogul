<?php

namespace roogul\enquiries\variables;

use Craft;
use craft\helpers\Html;
use craft\helpers\Template;
use roogul\enquiries\elements\db\SubmissionQuery;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;
use roogul\enquiries\Plugin;
use roogul\enquiries\services\Spam;
use Twig\Markup;
use yii\base\InvalidArgumentException;

/**
 * `craft.enquiries`
 */
class EnquiriesVariable
{
    public function form(string $handle): ?Form
    {
        return Plugin::getInstance()->getForms()->getFormByHandle($handle);
    }

    /**
     * @return Form[]
     */
    public function forms(): array
    {
        return Plugin::getInstance()->getForms()->getAllForms();
    }

    /**
     * Hidden inputs every form needs: the action, form handle, spam traps and
     * (if enabled) the Turnstile widget.
     *
     * Options:
     * - `redirect`: URL to send the visitor to after a successful submit (supports `{id}`)
     * - `turnstile`: false to leave the widget out and place it yourself with `craft.enquiries.turnstile()`
     */
    public function inputs(string $handle, array $options = []): Markup
    {
        $form = $this->requireForm($handle);
        $settings = Plugin::getInstance()->getSettings();
        $spam = Plugin::getInstance()->getSpam();

        $html = Html::actionInput('enquiries-by-roogul/submit')
            . Html::hiddenInput('form', $form->handle);

        if (!empty($options['redirect'])) {
            $html .= Html::redirectInput($options['redirect']);
        }

        $html .= Html::hiddenInput(Spam::TIMESTAMP_PARAM, $spam->timestampToken());

        $honeypotId = 'enq-hp-' . $form->handle . '-' . mt_rand(1000, 9999);
        $html .= Html::tag('div',
            Html::label(Craft::t('enquiries-by-roogul', 'Leave this field empty'), $honeypotId)
            . Html::textInput($settings->honeypotName, '', [
                'id' => $honeypotId,
                'tabindex' => '-1',
                'autocomplete' => 'off',
            ]),
            [
                'aria-hidden' => 'true',
                'style' => 'position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;',
            ],
        );

        if (($options['turnstile'] ?? true) !== false) {
            $html .= (string)$this->turnstile($handle);
        }

        return Template::raw($html);
    }

    /**
     * The Turnstile widget, if it's enabled for the form and keys are set.
     */
    public function turnstile(string $handle, array $attributes = []): Markup
    {
        $form = $this->requireForm($handle);

        if (!Plugin::getInstance()->getSpam()->turnstileEnabled($form)) {
            return Template::raw('');
        }

        $view = Craft::$app->getView();
        $view->registerJsFile('https://challenges.cloudflare.com/turnstile/v0/api.js', [
            'async' => true,
            'defer' => true,
        ], 'enquiries-turnstile');

        return Template::raw(Html::tag('div', '', array_merge([
            'class' => 'cf-turnstile',
            'data-sitekey' => Plugin::getInstance()->getSettings()->getTurnstileSiteKey(),
        ], $attributes)));
    }

    /**
     * The submission being re-shown after a failed (non-AJAX) submit, or a
     * blank one. Use it to repopulate values and show errors:
     *
     * ```twig
     * {% set enquiry = craft.enquiries.submission('contact') %}
     * <input name="fields[email]" value="{{ enquiry.value('email') }}">
     * {{ enquiry.getFirstError('email') }}
     * ```
     */
    public function submission(string $handle): Submission
    {
        $form = $this->requireForm($handle);
        $posted = Craft::$app->getUrlManager()->getRouteParams()['enquiry'] ?? null;

        if ($posted instanceof Submission && $posted->formId === $form->id) {
            return $posted;
        }

        $blank = new Submission();
        $blank->formId = $form->id;

        return $blank;
    }

    /**
     * The success message after a non-AJAX submit, or null.
     */
    public function success(string $handle): ?string
    {
        return Craft::$app->getSession()->getFlash('enquiries.success.' . $handle);
    }

    public function submissions(array $criteria = []): SubmissionQuery
    {
        return Craft::configure(Submission::find(), $criteria);
    }

    private function requireForm(string $handle): Form
    {
        $form = $this->form($handle);

        if (!$form) {
            throw new InvalidArgumentException("No Enquiries form with the handle “{$handle}”. Create it under Enquiries → Forms.");
        }

        return $form;
    }
}
