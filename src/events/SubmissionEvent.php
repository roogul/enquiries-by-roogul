<?php

namespace roogul\enquiries\events;

use craft\events\CancelableEvent;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;

/**
 * Fired before and after a front-end submission is handled.
 *
 * In EVENT_BEFORE_SUBMIT you can add errors (`$event->submission->addError('email', '...')`),
 * mark it as spam (`$event->isSpam = true`), or set `$event->isValid = false` to stop it.
 */
class SubmissionEvent extends CancelableEvent
{
    public Submission $submission;
    public Form $form;
    public bool $isSpam = false;
}
