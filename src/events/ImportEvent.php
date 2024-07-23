<?php

namespace dgrigg\migrationassistant\events;

use craft\events\CancelableEvent;
use yii\base\Component;

/**
 * Migration ImportEvent class.
 */
class ImportEvent extends CancelableEvent
{
    // Properties
    // =========================================================================

    /**
     * @var Model|null The element to be imported
     */
    public $element;

    /**
     * @var Array The data used to create the element model
     */
    public $value;


    /**
     * @var BaseContentMigration|null The service performing the import.
     */
    public $service;

    /**
     * @var int|null the id of the owner/parent element
     */
    public $ownerId;

    /**
     * @var String|null the reason the event was cancelled
     */
    public $error;


}
