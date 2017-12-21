<?php


namespace ZuluCrypto\StellarSdk\Transaction;

use ZuluCrypto\StellarSdk\Horizon\ApiClient;
use ZuluCrypto\StellarSdk\Util\MathSafety;
use ZuluCrypto\StellarSdk\Xdr\Iface\XdrEncodableInterface;
use ZuluCrypto\StellarSdk\Xdr\Type\VariableArray;
use ZuluCrypto\StellarSdk\Xdr\XdrEncoder;
use ZuluCrypto\StellarSdk\XdrModel\AccountId;
use ZuluCrypto\StellarSdk\XdrModel\Asset;
use ZuluCrypto\StellarSdk\XdrModel\Memo;
use ZuluCrypto\StellarSdk\XdrModel\Operation\ChangeTrustOp;
use ZuluCrypto\StellarSdk\XdrModel\Operation\CreateAccountOp;
use ZuluCrypto\StellarSdk\XdrModel\Operation\Operation;
use ZuluCrypto\StellarSdk\XdrModel\Operation\PaymentOp;
use ZuluCrypto\StellarSdk\XdrModel\TimeBounds;
use ZuluCrypto\StellarSdk\XdrModel\TransactionEnvelope;


/**
 * todo: rename to Transaction
 * Helper class to build a transaction on the Stellar network
 *
 * References:
 *  Debugging / testing:
 *      https://www.stellar.org/laboratory/
 *
 *  Retrieve fee information from:
 *      https://www.stellar.org/developers/horizon/reference/endpoints/ledgers-single.html
 *      https://www.stellar.org/developers/horizon/reference/resources/ledger.html
 *
 * Notes:
 *  - Per-operation fee is 100 stroops (0.00001 XLM)
 *  - Base reserve is 10 XLM
 *      - Minimum balance for an account is base reserve * 2
 *      - Each additional trustline, offer, signer, and data entry requires another 10 XLM
 *
 *
 * Format of a transaction:
 *  Source Address (AddressId)
 *      type
 *      address
 *  Fee (Uint32)
 *  Next sequence number (SequenceNumber - uint64)
 *      ...
 *  Time bounds (TimeBounds)
 *  Memo (Memo)
 *  Operations (Operation[])
 *  ext (TransactionExt) - extra? currently is a union with no arms
 */
class TransactionBuilder implements XdrEncodableInterface
{
    /**
     * Base-32 account ID
     *
     * @var AccountId
     */
    private $accountId;

    /**
     * @var TimeBounds
     */
    private $timeBounds;

    /**
     * @var Memo
     */
    private $memo;

    /**
     * @var VariableArray[]
     */
    private $operations;

    /**
     * Horizon API client, used for retrieving sequence numbers and validating
     * transaction
     *
     * @var ApiClient
     */
    private $apiClient;

    /**
     * TransactionBuilder constructor.
     *
     * @param $sourceAccountId
     * @return TransactionBuilder
     */
    public function __construct($sourceAccountId)
    {
        $this->accountId = new AccountId($sourceAccountId);

        $this->timeBounds = new TimeBounds();
        $this->memo = new Memo(Memo::MEMO_TYPE_NONE);
        $this->operations = new VariableArray();

        return $this;
    }

    /**
     * @return TransactionEnvelope
     */
    public function getTransactionEnvelope()
    {
        return new TransactionEnvelope($this);
    }

    /**
     * @param $secretKeyString
     * @return TransactionEnvelope
     */
    public function sign($secretKeyString)
    {
        return (new TransactionEnvelope($this))->sign($secretKeyString);
    }

    public function hash()
    {
        return $this->apiClient->hash($this);
    }

    public function getHashAsString()
    {
        return $this->apiClient->getHashAsString($this);
    }

    /**
     * @param $secretKeyString
     * @return \ZuluCrypto\StellarSdk\Horizon\Api\HorizonResponse
     */
    public function submit($secretKeyString)
    {
        return $this->apiClient->submitTransaction($this, $secretKeyString);
    }

    public function getFee()
    {
        // todo: calculate real fee
        return 100;
    }

    /**
     * @param string  $newAccountId
     * @param int     $amount
     * @param string  $sourceAccountId
     * @return TransactionBuilder
     */
    public function addCreateAccountOp($newAccountId, $amount, $sourceAccountId = null)
    {
        return $this->addOperation(new CreateAccountOp(new AccountId($newAccountId), $amount, $sourceAccountId));
    }

    /**
     * @param Asset $asset
     * @param       $amount
     * @param       $destinationAccountId
     * @return TransactionBuilder
     */
    public function addCustomAssetPaymentOp(Asset $asset, $amount, $destinationAccountId)
    {
        return $this->addOperation(
            PaymentOp::newCustomPayment(null, $destinationAccountId, $amount, $asset->getAssetCode(), $asset->getIssuer()->getAccountIdString())
        );
    }

    /**
     * @param Asset $asset
     * @param       $amount
     * @param null  $sourceAccountId
     * @return TransactionBuilder
     */
    public function addChangeTrustOp(Asset $asset, $amount, $sourceAccountId = null)
    {
        return $this->addOperation(new ChangeTrustOp($asset, $amount, $sourceAccountId));
    }

    /**
     * @return string
     */
    public function toXdr()
    {
        $bytes = '';

        // Account ID (36 bytes)
        $bytes .= $this->accountId->toXdr();
        // Fee (4 bytes)
        $bytes .= XdrEncoder::unsignedInteger($this->getFee());
        // Sequence number (8 bytes)
        $bytes .= XdrEncoder::unsignedInteger64($this->generateSequenceNumber());
        // Time Bounds (4 bytes if empty, 20 bytes if set)
        $bytes .= $this->timeBounds->toXdr();
        // Memo (4 bytes if empty, 36 bytes maximum)
        $bytes .= $this->memo->toXdr();

        // Operations
        $bytes .= $this->operations->toXdr();

        // TransactionExt (union reserved for future use)
        $bytes .= XdrEncoder::unsignedInteger(0);

        return $bytes;
    }

    /**
     * @param $operation
     * @return TransactionBuilder
     */
    public function addOperation($operation)
    {
        $this->operations->append($operation);

        return $this;
    }

    /**
     * @param $memo
     * @return $this
     */
    public function setTextMemo($memo)
    {
        $this->memo = new Memo(Memo::MEMO_TYPE_TEXT, $memo);

        return $this;
    }

    /**
     * @param $memo
     * @return $this
     */
    public function setIdMemo($memo)
    {
        $this->memo = new Memo(Memo::MEMO_TYPE_ID, $memo);

        return $this;
    }

    /**
     * Note: this should be called with the raw sha256 hash
     *
     * For example:
     *  $builder->setHashMemo(hash('sha256', 'example thing being hashed', true));
     *
     * @param $memo 32-byte sha256 hash
     * @return $this
     */
    public function setHashMemo($memo)
    {
        $this->memo = new Memo(Memo::MEMO_TYPE_HASH, $memo);

        return $this;
    }

    /**
     * Note: this should be called with the raw sha256 hash
     *
     * For example:
     *  $builder->setReturnMemo(hash('sha256', 'example thing being hashed', true));
     *
     * @param $memo 32-byte sha256 hash
     * @return $this
     */
    public function setReturnMemo($memo)
    {
        $this->memo = new Memo(Memo::MEMO_TYPE_RETURN, $memo);

        return $this;
    }

    /**
     * @param \DateTime $lowerTimebound
     * @return $this
     */
    public function setLowerTimebound(\DateTime $lowerTimebound)
    {
        $this->timeBounds->setMinTime($lowerTimebound);

        return $this;
    }

    /**
     * @param \DateTime $upperTimebound
     * @return $this
     */
    public function setUpperTimebound(\DateTime $upperTimebound)
    {
        $this->timeBounds->setMaxTime($upperTimebound);

        return $this;
    }

    protected function generateSequenceNumber()
    {
        $this->ensureApiClient();

        return $this->apiClient
                ->getAccount($this->accountId->getAccountIdString())
                ->getSequence() + 1
        ;
    }

    protected function ensureApiClient()
    {
        if (!$this->apiClient) throw new \ErrorException("An API client is required, call setApiClient before using this method");
    }

    /**
     * @return ApiClient
     */
    public function getApiClient()
    {
        return $this->apiClient;
    }

    /**
     * @param ApiClient $apiClient
     * @return TransactionBuilder
     */
    public function setApiClient($apiClient)
    {
        $this->apiClient = $apiClient;

        return $this;
    }
}