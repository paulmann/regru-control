<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\ValidationException;
use RegRuControl\Model\DnsRecord;
use RegRuControl\Model\RecordType;
use RegRuControl\RegRu\Contract\ZoneApiInterface;
use RegRuControl\Repository\DnsRecordRepository;
use RegRuControl\Repository\DomainRepository;
use RegRuControl\Repository\SettingsRepository;
use RegRuControl\Support\Json;

/**
 * DNS zone management with a local read-through cache.
 *
 * REG.RU has no record ids, so every mutation is expressed as an ordered
 * action list and followed by a cache refresh — what the UI shows is always
 * what the registry just returned.
 */
final readonly class DnsService
{
    private const SOA_SETTING_PREFIX = 'dns.soa.';

    public function __construct(
        private ZoneApiInterface $zoneApi,
        private DomainRepository $domains,
        private DnsRecordRepository $records,
        private SettingsRepository $settings,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{records: list<DnsRecord>, soa: array<string, mixed>, dns_managed: bool, synced_at: ?string, source: string}
     */
    public function listing(string $dname, bool $refresh = false): array
    {
        $domain = $this->assertManaged($dname);

        $cached = $this->records->forDomain($dname);
        $source = 'cache';

        if ($refresh || $cached === []) {
            $this->syncFromApi($dname);
            $cached = $this->records->forDomain($dname);
            $source = 'api';
        }

        return [
            'records' => $cached,
            'soa' => $this->soa($dname),
            'dns_managed' => $domain->dnsManaged,
            'synced_at' => $this->records->syncedAt($dname),
            'source' => $source,
        ];
    }

    /**
     * Pulls the zone from REG.RU into the local cache.
     *
     * @return array{records: list<DnsRecord>, soa: array<string, mixed>}
     */
    public function syncFromApi(string $dname): array
    {
        $this->assertManaged($dname);
        $zones = $this->zoneApi->records([$dname]);
        $zone = $zones[$dname] ?? null;

        if ($zone === null) {
            throw new HttpException(502, 'REGRU_EMPTY_RESPONSE', 'REG.RU не вернул данные зоны для ' . $dname . '.');
        }

        if ($zone['error'] !== null) {
            if ($zone['error'] === 'DOMAIN_IS_NOT_USE_REGRU_NSS') {
                $this->domains->setDnsManaged($dname, false);

                throw new HttpException(
                    409,
                    'DNS_NOT_MANAGED',
                    sprintf('Домен %s не использует DNS-серверы REG.RU (ns1.reg.ru / ns2.reg.ru).', $dname),
                );
            }

            throw new HttpException(502, 'REGRU_ZONE_ERROR', 'REG.RU вернул ошибку зоны: ' . $zone['error']);
        }

        $records = [];

        foreach ($zone['rrs'] as $rr) {
            $record = DnsRecord::fromApi($rr, $dname);

            if ($record->rectype !== '' && $record->content !== '') {
                $records[$record->key()] = $record;
            }
        }

        $this->records->replaceForDomain($dname, array_values($records));
        $this->domains->setDnsManaged($dname, true);

        if ($zone['soa'] !== []) {
            $this->settings->set(self::SOA_SETTING_PREFIX . $dname, Json::encode($zone['soa']));
        }

        return ['records' => array_values($records), 'soa' => $zone['soa']];
    }

    /**
     * @return array{answer: array<string, mixed>, records: list<DnsRecord>}
     */
    public function add(string $dname, string $subname, string $rectype, string $content, int $prio): array
    {
        $this->assertManaged($dname);
        $record = $this->validatedRecord($dname, $subname, $rectype, $content, $prio);
        $answer = $this->zoneApi->addRecord($dname, $record);
        $this->assertActionSucceeded($answer, $dname, 'добавление');
        $this->syncFromApi($dname);
        $this->audit->record('dns.add', $dname, ['record' => $record->toArray()]);

        return ['answer' => $answer, 'records' => $this->records->forDomain($dname)];
    }

    /**
     * @param array{subname: string, rectype: string, content: string, prio: int} $old
     * @return array{answer: array<string, mixed>, records: list<DnsRecord>}
     */
    public function update(string $dname, array $old, string $subname, string $rectype, string $content, int $prio): array
    {
        $this->assertManaged($dname);
        $oldRecord = DnsRecord::fromApi($old, $dname);
        $newRecord = $this->validatedRecord($dname, $subname, $rectype, $content, $prio);
        $answer = $this->zoneApi->replaceRecord($dname, $oldRecord, $newRecord);
        $this->assertActionSucceeded($answer, $dname, 'изменение');
        $this->syncFromApi($dname);
        $this->audit->record('dns.update', $dname, ['old' => $oldRecord->toArray(), 'new' => $newRecord->toArray()]);

        return ['answer' => $answer, 'records' => $this->records->forDomain($dname)];
    }

    /**
     * @param array{subname: string, rectype: string, content: string, prio: int} $record
     * @return array{answer: array<string, mixed>, records: list<DnsRecord>}
     */
    public function delete(string $dname, array $record): array
    {
        $this->assertManaged($dname);
        $model = DnsRecord::fromApi($record, $dname);

        if ($model->rectype === '' || $model->subname === '') {
            throw new ValidationException(['record' => 'Не указаны поддомен и тип записи.']);
        }

        $answer = $this->zoneApi->removeRecord($dname, $model);
        $this->assertActionSucceeded($answer, $dname, 'удаление');
        $this->syncFromApi($dname);
        $this->audit->record('dns.delete', $dname, ['record' => $model->toArray()]);

        return ['answer' => $answer, 'records' => $this->records->forDomain($dname)];
    }

    /**
     * @return array{answer: array<string, mixed>, soa: array<string, mixed>}
     */
    public function updateSoa(string $dname, string $ttl, string $minimumTtl): array
    {
        $this->assertManaged($dname);

        foreach (['ttl' => $ttl, 'minimum_ttl' => $minimumTtl] as $field => $value) {
            if (preg_match('/^\d+[smwhd]?$/i', $value) !== 1) {
                throw new ValidationException([$field => 'Формат TTL: число с суффиксом s/m/w/h/d, например 1h или 3600.']);
            }
        }

        $answer = $this->zoneApi->updateSoa($dname, $ttl, $minimumTtl);
        $this->assertActionSucceeded($answer, $dname, 'изменение SOA');
        $this->settings->set(self::SOA_SETTING_PREFIX . $dname, Json::encode(['ttl' => $ttl, 'minimum_ttl' => $minimumTtl]));
        $this->syncFromApi($dname);
        $this->audit->record('dns.soa', $dname, ['ttl' => $ttl, 'minimum_ttl' => $minimumTtl]);

        return ['answer' => $answer, 'soa' => ['ttl' => $ttl, 'minimum_ttl' => $minimumTtl]];
    }

    /**
     * @return array<string, mixed>
     */
    public function soa(string $dname): array
    {
        $raw = $this->settings->get(self::SOA_SETTING_PREFIX . $dname);

        if ($raw === null) {
            return [];
        }

        $decoded = Json::tryDecode($raw);

        return $decoded ?? [];
    }

    public function deleteAll(string $dname): void
    {
        $this->assertManaged($dname);
        $this->zoneApi->clear($dname);
        $this->records->replaceForDomain($dname, []);
        $this->audit->record('dns.clear', $dname, [], 'ok');
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function assertActionSucceeded(array $answer, string $dname, string $operation): void
    {
        $domains = $answer['domains'] ?? [];

        if (!is_array($domains) || $domains === []) {
            return;
        }

        foreach ($domains as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (($entry['dname'] ?? '') !== $dname) {
                continue;
            }

            $actions = $entry['action_list'] ?? [];

            if (is_array($actions)) {
                foreach ($actions as $action) {
                    if (!is_array($action)) {
                        continue;
                    }

                    if (($action['result'] ?? 'success') !== 'success') {
                        throw new HttpException(
                            502,
                            'DNS_ACTION_FAILED',
                            sprintf('REG.RU отклонил %s записи: %s', $operation, (string) ($action['error_text'] ?? $action['result'] ?? 'ошибка')),
                            ['action' => $action],
                        );
                    }
                }
            }

            if (isset($entry['error_code']) && $entry['error_code'] !== '') {
                throw new HttpException(
                    502,
                    'DNS_ACTION_FAILED',
                    sprintf('REG.RU отклонил %s записи: %s', $operation, (string) ($entry['error_text'] ?? $entry['error_code'])),
                    ['domain' => $entry],
                );
            }
        }
    }

    private function validatedRecord(string $dname, string $subname, string $rectype, string $content, int $prio): DnsRecord
    {
        $type = RecordType::fromApi($rectype);
        $errors = [];
        $subname = trim($subname);
        $content = trim($content);

        if ($subname === '') {
            $errors['subname'] = 'Укажите поддомен: @ для самого домена, * для всех поддоменов.';
        } elseif (preg_match('/^(@|\*|_?[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?)*)$/i', $subname) !== 1) {
            $errors['subname'] = 'Поддомен может содержать латиницу, цифры, дефис, подчёркивание и точки.';
        }

        if ($type === null) {
            $errors['rectype'] = 'Поддерживаются типы: A, AAAA, CNAME, MX, TXT, NS, SRV, CAA.';
        }

        if ($content === '') {
            $errors['content'] = 'Заполните содержимое записи.';
        }

        if ($prio < 0 || $prio > 65535) {
            $errors['prio'] = 'Приоритет — от 0 до 65535.';
        }

        if ($type !== null && $content !== '') {
            $typeError = $this->validateContent($type, $content);

            if ($typeError !== null) {
                $errors['content'] = $typeError;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var RecordType $type */
        return new DnsRecord(null, $dname, $subname, $type->value, $content, $prio);
    }

    private function validateContent(RecordType $type, string $content): ?string
    {
        return match ($type) {
            RecordType::A => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                ? 'Для A-записи нужен корректный IPv4-адрес.' : null,
            RecordType::AAAA => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false
                ? 'Для AAAA-записи нужен корректный IPv6-адрес.' : null,
            RecordType::CNAME, RecordType::MX, RecordType::NS => preg_match('/^(?=.{1,253}$)([a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z]{2,63}\.?$/i', $content) !== 1
                ? 'Ожидается доменное имя, например example.com.' : null,
            RecordType::TXT => mb_strlen($content) > 1000 ? 'TXT-запись длиннее 1000 символов.' : null,
            RecordType::SRV => preg_match('/^\d+\s+\d+\s+\S+$/', $content) !== 1
                ? 'SRV: укажите «вес порт цель», например «10 5060 sip.example.com».' : null,
            RecordType::CAA => mb_strlen($content) > 255 ? 'CAA-запись длиннее 255 символов.' : null,
        };
    }

    private function assertManaged(string $dname): \RegRuControl\Model\Domain
    {
        $domain = $this->domains->find($dname);

        if ($domain === null) {
            throw HttpException::notFound(sprintf('Домен %s не найден. Запустите синхронизацию.', $dname));
        }

        if (!$domain->dnsManaged) {
            throw new HttpException(
                409,
                'DNS_NOT_MANAGED',
                sprintf('Домен %s не делегирован на ns1.reg.ru / ns2.reg.ru — управление зоной через REG.RU недоступно.', $dname),
            );
        }

        return $domain;
    }
}
