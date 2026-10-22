<?php

declare(strict_types=1);

namespace RegRuControl\Http;

use RegRuControl\Model\CredentialKind;
use RegRuControl\Model\RecordType;
use RegRuControl\Model\ServiceState;
use RegRuControl\Model\ServiceType;
use RegRuControl\Model\TokenScope;
use RegRuControl\Service\SyncService;

/**
 * Single source of enumeration metadata for the SPA (labels, icons, hints).
 */
final class Meta
{
    /**
     * @return array<string, mixed>
     */
    public static function enums(): array
    {
        return [
            'service_types' => array_map(
                static fn (ServiceType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'category' => $type->category()->value,
                    'icon' => $type->icon(),
                    'can_have_panel_credentials' => $type->canHavePanelCredentials(),
                ],
                ServiceType::cases(),
            ),
            'service_categories' => [
                ['value' => 'domain', 'label' => 'Домены'],
                ['value' => 'hosting', 'label' => 'Хостинги'],
                ['value' => 'server', 'label' => 'Серверы'],
                ['value' => 'license', 'label' => 'Лицензии'],
                ['value' => 'addon', 'label' => 'Дополнительно'],
            ],
            'service_states' => array_map(
                static fn (ServiceState $state): array => [
                    'value' => $state->value,
                    'label' => $state->label(),
                    'tone' => $state->tone(),
                ],
                ServiceState::cases(),
            ),
            'record_types' => array_map(
                static fn (RecordType $type): array => [
                    'value' => $type->value,
                    'description' => $type->description(),
                    'content_label' => $type->contentLabel(),
                    'placeholder' => $type->placeholder(),
                    'supports_priority' => $type->supportsPriority(),
                ],
                RecordType::all(),
            ),
            'credential_kinds' => array_map(
                static fn (CredentialKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'icon' => $kind->icon(),
                ],
                CredentialKind::all(),
            ),
            'token_scopes' => array_map(
                static fn (TokenScope $scope): array => [
                    'value' => $scope->value,
                    'label' => $scope->label(),
                    'description' => $scope->description(),
                ],
                TokenScope::all(),
            ),
            'sync_scopes' => [
                ['value' => 'all', 'label' => 'Полная синхронизация'],
                ['value' => 'services', 'label' => 'Услуги и домены'],
                ['value' => 'details', 'label' => 'Данные хостингов'],
                ['value' => 'dns', 'label' => 'DNS-зоны'],
            ],
        ];
    }
}
