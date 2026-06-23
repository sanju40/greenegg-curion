<?php

namespace App\Exceptions;

/**
 * Sync Operation Exception
 */
class SyncException extends \Exception
{
    protected $entityType;
    protected $entityId;

    public function __construct($message = "", $entityType = null, $entityId = null, $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->entityType = $entityType;
        $this->entityId = $entityId;
    }

    public function getEntityType()
    {
        return $this->entityType;
    }

    public function getEntityId()
    {
        return $this->entityId;
    }
}

