<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Proxy\Fixtures;

use SybaseORM\Attribute\Column;
use SybaseORM\Attribute\Entity;
use SybaseORM\Attribute\Id;
use SybaseORM\Attribute\JoinColumn;
use SybaseORM\Attribute\ManyToOne;

#[Entity(table: 'articles')]
class ArticleEntity
{
    #[Id]
    #[Column(type: 'integer')]
    private ?int $id = null;

    #[Column(type: 'string', length: 255)]
    private string $title = '';

    #[Column(type: 'string', nullable: true)]
    private ?string $content = null;

    #[Column(type: 'boolean')]
    private bool $published = false;

    #[ManyToOne(targetEntity: 'AuthorEntity', inversedBy: 'articles', fetch: 'LAZY')]
    #[JoinColumn(name: 'author_id', referencedColumnName: 'id')]
    private ?object $author = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): void
    {
        $this->published = $published;
    }

    public function getAuthor(): ?object
    {
        return $this->author;
    }

    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'published' => $this->published,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'] ?? null;
        $this->title = $data['title'] ?? '';
        $this->content = $data['content'] ?? null;
        $this->published = $data['published'] ?? false;
    }
}
