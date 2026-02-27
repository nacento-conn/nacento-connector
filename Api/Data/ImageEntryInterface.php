<?php
/**
 * Copyright © Nacento
 */
declare(strict_types=1);

namespace Nacento\Connector\Api\Data;

/**
 * Represents a single image entry within a product's media gallery.
 * @api
 */
interface ImageEntryInterface
{
    /** Constants for data array keys. */
    public const FILE_PATH = 'file_path';
    public const LABEL = 'label';
    public const DISABLED = 'disabled';
    public const POSITION = 'position';
    public const ROLES = 'roles';

    /**
     * Retrieves the path to the image file.
     * Expected format is a Magento media-gallery file path.
     * Example: "/a/b/image.jpg" or "a/b/image.jpg".
     * @return string
     */
    public function getFilePath(): string;
    /**
     * Sets the path for the image file.
     * @param string $filePath The media file path.
     * @return $this
     */
    public function setFilePath(string $filePath): self;

    /**
     * Gets the descriptive label for the image (often used as alt text).
     * @return string
     */
    public function getLabel(): string;
    /**
     * Sets the descriptive label for the image.
     * @param string $label The image label.
     * @return $this
     */
    public function setLabel(string $label): self;
    
    /**
     * Checks if the image is disabled (hidden from the frontend).
     * @return bool
     */
    public function isDisabled(): bool;
    /**
     * Sets the disabled status of the image.
     * @param bool $disabled True to disable, false to enable.
     * @return $this
     */
    public function setDisabled(bool $disabled): self;

    /**
     * Gets the sort order (position) of the image within the gallery.
     * @return int
     */
    public function getPosition(): int;
    /**
     * Sets the sort order (position) of the image.
     * @param int $position The position index.
     * @return $this
     */
    public function setPosition(int $position): self;

    /**
     * Gets the assigned roles for the image (e.g., 'image', 'small_image', 'thumbnail').
     * @return string[]
     */
    public function getRoles(): array;
    /**
     * Sets the roles for the image.
     * @param string[] $roles An array of image roles.
     * @return $this
     */
    public function setRoles(array $roles): self;
}
