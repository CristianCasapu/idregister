<?php

declare(strict_types=1);

namespace OCA\IdRegister\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getSurname()
 * @method void setSurname(string $surname)
 * @method string getGivenNames()
 * @method void setGivenNames(string $givenNames)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getPhone()
 * @method void setPhone(string $phone)
 * @method string getCnpHash()
 * @method void setCnpHash(string $cnpHash)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getCode()
 * @method void setCode(string $code)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 */
class PendingRegistration extends Entity
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_ACTIVE = 'active';

    protected $uid;
    protected $surname;
    protected $givenNames;
    protected $email;
    protected $phone;
    protected $cnpHash;
    protected $token;
    protected $code;
    protected $attempts;
    protected $status;
    protected $createdAt;
    protected $expiresAt;

    public function __construct()
    {
        $this->addType('uid', 'string');
        $this->addType('surname', 'string');
        $this->addType('givenNames', 'string');
        $this->addType('email', 'string');
        $this->addType('phone', 'string');
        $this->addType('cnpHash', 'string');
        $this->addType('token', 'string');
        $this->addType('code', 'string');
        $this->addType('attempts', 'integer');
        $this->addType('status', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('expiresAt', 'integer');
    }

    public function getFullName(): string
    {
        return trim($this->getGivenNames().' '.$this->getSurname());
    }
}
