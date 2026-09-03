<?php

return [
    'name' => 'Example',
    'version' => '1.0.0',

    /*
     * The proxies whose forwarded client address headers may be believed.
     *
     * The default is to trust none, so X-Forwarded-For and CF-Connecting-IP are
     * ignored and the connecting address identifies the caller. That default is
     * deliberate: a caller can set those headers freely, so trusting them from
     * an unknown source would let one client present itself as an unlimited
     * number of distinct callers and walk straight past any rate limit.
     *
     * List the addresses of your own proxies or CDN once you know them. Exact
     * addresses, CIDR ranges and '*' (trust every proxy) are all accepted;
     * use '*' only where something in front of the application already strips
     * incoming forwarding headers.
     *
     * 'trusted_proxies' => ['192.0.2.10', '198.51.100.0/24'],
     */
    'trusted_proxies' => [],
    'mail' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'example@example.com',
        'password' => 'password',
        'encryption' => 'tls',
        'from' => 'example@example.com',
        'from_name' => 'Example',
    ]
];