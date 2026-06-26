<?php

declare(strict_types=1);

namespace Phlix\Console\Api\Dto\Admin;

/**
 * The aggregate remote-access status: the four sub-area statuses (hub pairing,
 * managed subdomain, relay tunnel, port forwarding) fetched together and held as
 * one immutable value. Built via {@see fromParts()} from the four independent
 * GETs the {@see \Phlix\Console\Api\Admin\AdminClient::remoteStatus()} fans out.
 */
final readonly class RemoteAccessStatus
{
    public function __construct(
        public HubStatus $hub,
        public SubdomainStatus $subdomain,
        public RelayStatus $relay,
        public PortForwardStatus $portForward,
    ) {
    }

    /**
     * Assemble the aggregate from its four already-mapped sub-DTOs.
     */
    public static function fromParts(
        HubStatus $hub,
        SubdomainStatus $subdomain,
        RelayStatus $relay,
        PortForwardStatus $portForward,
    ): self {
        return new self($hub, $subdomain, $relay, $portForward);
    }
}
