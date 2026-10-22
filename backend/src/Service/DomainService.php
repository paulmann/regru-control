<?php

declare(strict_types=1);

namespace RegRuControl\Service;

use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\HttpException;
use RegRuControl\Kernel\ValidationException;
use RegRuControl\Model\Domain;
use RegRuControl\RegRu\Contract\DomainApiInterface;
use RegRuControl\RegRu\Contract\ServicesApiInterface;
use RegRuControl\Repository\DomainRepository;

/**
 * Domain-facing operations: renewals, autorenew, NS changes, price list.
 */
final readonly class DomainService
{
    public function __construct(
        private DomainRepository $domains,
        private DomainApiInterface $domainApi,
        private ServicesApiInterface $servicesApi,
        private AuditService $audit,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<Domain>, total: int, page: int, per_page: int}
     */
    public function search(array $filters): array
    {
        return $this->domains->search($filters);
    }

    public function get(string $dname): Domain
    {
        $domain = $this->domains->find($dname);

        if ($domain === null) {
            throw HttpException::notFound(sprintf('Домен %s не найден в локальной базе. Запустите синхронизацию.', $dname));
        }

        return $domain;
    }

    /**
     * @return array<string, mixed>
     */
    public function renew(string $dname, int $period): array
    {
        if ($period < 1 || $period > 10) {
            throw new ValidationException(['period' => 'Период продления — от 1 до 10 лет.']);
        }

        $domain = $this->get($dname);

        if (!$domain->state->isActive() && $domain->state->value !== 'S') {
            throw HttpException::conflict(sprintf('Домен %s нельзя продлить в состоянии «%s».', $dname, $domain->state->label()));
        }

        $answer = $this->servicesApi->renew($domain->serviceId, $period);
        $this->audit->record('domain.renew', $dname, ['period' => $period, 'answer' => $answer]);

        return $answer;
    }

    /**
     * @return array<string, mixed>
     */
    public function setAutorenew(string $dname, bool $flag): array
    {
        $domain = $this->get($dname);
        $answer = $this->servicesApi->setAutorenew($domain->dname, $flag);
        $this->domains->setAutorenew($dname, $flag);
        $this->audit->record('domain.autorenew', $dname, ['enabled' => $flag]);

        return $answer;
    }

    /**
     * @param list<string> $nss
     * @param array<string, string> $ips hostname => IP list
     * @return array<string, mixed>
     */
    public function updateNss(string $dname, array $nss, array $ips = [], bool $undelegate = false): array
    {
        $domain = $this->get($dname);
        $nss = array_values(array_filter(array_map('trim', $nss)));

        if (!$undelegate) {
            if (count($nss) < 2) {
                throw new ValidationException(['nss' => 'Укажите минимум два DNS-сервера или снимите домен с делегирования.']);
            }

            if (count($nss) > 4) {
                throw new ValidationException(['nss' => 'Не более четырёх DNS-серверов.']);
            }

            $errors = [];

            foreach ($nss as $index => $host) {
                if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i', $host) !== 1) {
                    $errors['nss'] = sprintf('DNS-сервер «%s» выглядит некорректно.', $host);

                    break;
                }

                $nss[$index] = rtrim($host, '.');
            }

            if ($errors !== []) {
                throw new ValidationException($errors);
            }
        }

        $answer = $this->domainApi->updateNss($domain->dname, $undelegate ? [] : $nss, $ips, $undelegate);

        if (!$undelegate) {
            $this->domains->updateNss($dname, $nss);
            $this->domains->setDnsManaged($dname, $this->usesRegRuDns($nss));
        }

        $this->audit->record('domain.nss', $dname, ['nss' => $nss, 'undelegate' => $undelegate]);

        return $answer;
    }

    /**
     * Refreshes NS data from REG.RU for one domain.
     */
    public function refreshNameservers(string $dname): Domain
    {
        $domain = $this->get($dname);
        $map = $this->domainApi->getNss([$domain->dname]);
        $entries = $map[$domain->dname] ?? [];
        $nss = [];

        foreach ($entries as $entry) {
            if (isset($entry['ns']) && is_string($entry['ns'])) {
                $nss[] = rtrim($entry['ns'], '.');
            }
        }

        $this->domains->updateNss($dname, $nss);

        return $this->get($dname);
    }

    /**
     * @return array<string, mixed>
     */
    public function prices(): array
    {
        return $this->domainApi->getPrices();
    }

    /**
     * @param list<string> $nss
     */
    public function usesRegRuDns(array $nss): bool
    {
        $normalized = array_map(static fn (string $ns): string => strtolower(rtrim(trim($ns), '.')), $nss);

        return in_array('ns1.reg.ru', $normalized, true) || in_array('ns2.reg.ru', $normalized, true);
    }
}
