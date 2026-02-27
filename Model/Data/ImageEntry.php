<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Model\Data;

use Magento\Framework\DataObject;
use Nacento\Connector\Api\Data\ImageEntryInterface;

/**
 * Data model for a media gallery entry.
 * Implements the ImageEntryInterface to provide structured data access for image properties.
 * @see \Nacento\Connector\Api\Data\ImageEntryInterface
 */
class ImageEntry extends DataObject implements ImageEntryInterface
{
    /**
     * @inheritdoc
     */
    public function getFilePath(): string
    {
        return (string)$this->getData(self::FILE_PATH);
    }

    /**
     * @inheritdoc
     */
    public function setFilePath(string $filePath): self
    {
        return $this->setData(self::FILE_PATH, $filePath);
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return (string)$this->getData(self::LABEL);
    }

    /**
     * @inheritdoc
     */
    public function setLabel(string $label): self
    {
        return $this->setData(self::LABEL, $label);
    }

    /**
     * @inheritdoc
     */
    public function isDisabled(): bool
    {
        return (bool)$this->getData(self::DISABLED);
    }

    /**
     * @inheritdoc
     */
    public function setDisabled(bool $disabled): self
    {
        return $this->setData(self::DISABLED, $disabled);
    }

    /**
     * @inheritdoc
     */
    public function getPosition(): int
    {
        return (int)$this->getData(self::POSITION);
    }

    /**
     * @inheritdoc
     */
    public function setPosition(int $position): self
    {
        return $this->setData(self::POSITION, $position);
    }

    /**
     * @inheritdoc
     */
    public function getRoles(): array
    {
        $roles = $this->getData(self::ROLES);
        return is_array($roles) ? $roles : [];
    }

    /**
     * @inheritdoc
     */
    public function setRoles(array $roles): self
    {
        return $this->setData(self::ROLES, $roles);
    }
}
