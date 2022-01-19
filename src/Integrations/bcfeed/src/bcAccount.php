<?php
/**
 * Class bcAccount
 *
 * Validates and stores the credentials for a BrightCove CMS API account
 * Read-only after construction -- shouldn't recycle or change the values after creating it.
 * Reference docs: https://support.brightcove.com/api-authentication
 *
 */


namespace FranOntanaya;


class bcAccount {
    private $accountId;
    private $clientId;
    private $clientSecret;

    function __construct(array $account) {
        if (!isset($account['account_id'])) {
            throw new \InvalidArgumentException('Missing account_id key in new bcAccount instance.');
        }
        if (!isset($account['client_id'])) {
            throw new \InvalidArgumentException('Missing client_id key in new bcAccount instance.');
        }
        if (!isset($account['client_secret'])) {
            throw new \InvalidArgumentException('Missing client_secret key in new bcAccount instance.');
        }
        $this->validateAccountId($account['account_id']);
        $this->validateClientId($account['client_id']);
        $this->validateClientSecret($account['client_secret']);
        $this->accountId = $account['account_id'];
        $this->clientId = $account['client_id'];
        $this->clientSecret = $account['client_secret'];
    }

    function validateAccountId($accountId) {
        // No official spec found for this value, but it's typically 13 numbers
        if (!preg_match('/^[0-9]{10,15}$/', $accountId)) {
            throw new \InvalidArgumentException('Invalid account_id value.');
        }
    }
    function validateClientId($clientId) {
        if (!preg_match('/^[a-z0-9]{8}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{12}$/', $clientId)) {
            throw new \InvalidArgumentException('Invalid client_id value.');
        }
    }
    function validateClientSecret($clientSecret) {
        if (!preg_match('/^[a-zA-Z0-9_\-]{86}$/', $clientSecret)) {
            throw new \InvalidArgumentException('Invalid client_secret value.');
        }
    }

    function getAccountId() { return $this->accountId; }

    function getClientId() { return $this->clientId; }

    function getClientSecret() { return $this->clientSecret; }
}
