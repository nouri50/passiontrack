<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'userNotifications')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $message = null;

    #[ORM\ManyToOne(inversedBy: 'notifications')]
    private ?Session $related_session = null;

    #[ORM\ManyToOne(inversedBy: 'notifications')]
    private ?Analysis $related_analysis = null;

    #[ORM\Column]
    private ?bool $is_read = null;

    #[ORM\Column(length: 20)]
    private ?string $channel = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $set_at = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $read_at = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getRelatedSession(): ?Session
    {
        return $this->related_session;
    }

    public function setRelatedSession(?Session $related_session): static
    {
        $this->related_session = $related_session;

        return $this;
    }

    public function getRelatedAnalysis(): ?Analysis
    {
        return $this->related_analysis;
    }

    public function setRelatedAnalysis(?Analysis $related_analysis): static
    {
        $this->related_analysis = $related_analysis;

        return $this;
    }

    public function isRead(): ?bool
    {
        return $this->is_read;
    }

    public function setIsRead(bool $is_read): static
    {
        $this->is_read = $is_read;

        return $this;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getSetAt(): ?\DateTimeImmutable
    {
        return $this->set_at;
    }

    public function setSetAt(\DateTimeImmutable $set_at): static
    {
        $this->set_at = $set_at;

        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->read_at;
    }

    public function setReadAt(?\DateTimeImmutable $read_at): static
    {
        $this->read_at = $read_at;

        return $this;
    }
}
