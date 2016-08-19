<?php

namespace OfxParser;

use SimpleXMLElement;
use OfxParser\Entities\AccountInfo;
use OfxParser\Entities\BankAccount;
use OfxParser\Entities\Institute;
use OfxParser\Entities\SignOn;
use OfxParser\Entities\Statement;
use OfxParser\Entities\Status;
use OfxParser\Entities\Transaction;

/**
 * The OFX object
 *
 * Heavily refactored from Guillaume Bailleul's grimfor/ofxparser
 *
 * Second refactor by Oliver Lowe to unify the API across all
 * OFX data-types.
 *
 * Based on Andrew A Smith's Ruby ofx-parser
 *
 * @author Guillaume BAILLEUL <contact@guillaume-bailleul.fr>
 * @author James Titcumb <hello@jamestitcumb.com>
 * @author Oliver Lowe <mrtriangle@gmail.com>
 */
class Ofx
{
    public $Header;
    public $signOn;
    public $SignupAccountInfo;
    public $BankAccounts = [];
    public $bankAccount;
    public $Investment;

    /**
     * @param SimpleXMLElement $xml
     * @throws \Exception
     */
    public function __construct(SimpleXMLElement $xml)
    {
        $this->signOn = $this->buildSignOn($xml->SIGNONMSGSRSV1->SONRS);
        $this->SignupAccountInfo = $this->buildAccountInfo($xml->SIGNUPMSGSRSV1->ACCTINFOTRNRS);

        if (isset($xml->BANKMSGSRSV1)) {
            $this->BankAccounts = $this->buildBankAccounts($xml);
        } elseif (isset($xml->CREDITCARDMSGSRSV1)) {
            $this->BankAccounts = $this->buildCreditAccounts($xml);
        }


        // Set a helper if only one bank account
        if (count($this->BankAccounts) === 1) {
            $this->bankAccount = $this->BankAccounts[0];
        }
    }

    /**
     * Get the transactions that have been processed
     *
     * @return array
     */
    public function getTransactions()
    {
        return $this->bankAccount->Statement->Transactions;
    }

    /**
     * @param $xml
     * @return SignOn
     * @throws \Exception
     */
    private function buildSignOn($xml)
    {
        $signOn = new SignOn();
        $signOn->status = $this->buildStatus($xml->STATUS);
        $signOn->date = $this->createDateTimeFromStr($xml->DTSERVER, true);
        $signOn->language = $xml->LANGUAGE;

        $signOn->institute = new Institute();
        $signOn->institute->name = $xml->FI->ORG;
        $signOn->institute->id = $xml->FI->FID;

        return $signOn;
    }

    /**
     * @param $xml
     * @return array AccountInfo
     */
    private function buildAccountInfo($xml)
    {
        if (!isset($xml->ACCTINFO)) {
            return [];
        }

        $accounts = [];
        foreach ($xml->ACCTINFO as $account) {
            $accountInfo = new AccountInfo();
            $accountInfo->desc = $account->DESC;
            $accountInfo->number = $account->ACCTID;
            $accounts[] = $accountInfo;
        }

        return $accounts;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return array
     * @throws \Exception
     */
    private function buildCreditAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];

        foreach ($xml->CREDITCARDMSGSRSV1->CCSTMTTRNRS as $accountStatement) {
            $bankAccounts[] = $this->buildCreditAccount($accountStatement);
        }
        return $bankAccounts;
    }

    /**
     * @param SimpleXMLElement $xml
     * @return array
     * @throws \Exception
     */
    private function buildBankAccounts(SimpleXMLElement $xml)
    {
        // Loop through the bank accounts
        $bankAccounts = [];
        foreach ($xml->BANKMSGSRSV1->STMTTRNRS as $accountStatement) {
            $bankAccounts[] = $this->buildBankAccount($accountStatement);
        }
        return $bankAccounts;
    }

    /**
     * @param $xml
     * @return BankAccount
     * @throws \Exception
     */
    private function buildBankAccount($xml)
    {
        $bankAccount = new BankAccount();
        $bankAccount->transactionUid = $xml->TRNUID;
        $bankAccount->agencyNumber = $xml->STMTRS->BANKACCTFROM->BRANCHID;
        $bankAccount->accountNumber = $xml->STMTRS->BANKACCTFROM->ACCTID;
        $bankAccount->routingNumber = $xml->STMTRS->BANKACCTFROM->BANKID;
        $bankAccount->accountType = $xml->STMTRS->BANKACCTFROM->ACCTTYPE;
        $bankAccount->balance = $xml->STMTRS->LEDGERBAL->BALAMT;
        $bankAccount->balanceDate = $this->createDateTimeFromStr($xml->STMTRS->LEDGERBAL->DTASOF, true);

        $bankAccount->statement = new Statement();
        $bankAccount->statement->currency = $xml->STMTRS->CURDEF;
        $bankAccount->statement->startDate = $this->createDateTimeFromStr($xml->STMTRS->BANKTRANLIST->DTSTART);
        $bankAccount->statement->endDate = $this->createDateTimeFromStr($xml->STMTRS->BANKTRANLIST->DTEND);
        $bankAccount->statement->transactions = $this->buildTransactions($xml->STMTRS->BANKTRANLIST->STMTTRN);

        return $bankAccount;
    }

    /**
     * @param $xml
     * @return BankAccount
     * @throws \Exception
     */
    private function buildCreditAccount($xml)
    {
        $creditAccount = new BankAccount();
        $creditAccount->transactionUid = $xml->TRNUID;
        $creditAccount->agencyNumber = $xml->CCSTMTRS->BANKACCTFROM->BRANCHID;
        $creditAccount->accountNumber = $xml->CCSTMTRS->BANKACCTFROM->ACCTID;
        $creditAccount->routingNumber = $xml->CCSTMTRS->BANKACCTFROM->BANKID;
        $creditAccount->accountType = $xml->CCSTMTRS->BANKACCTFROM->ACCTTYPE;
        $creditAccount->balance = $xml->CCSTMTRS->LEDGERBAL->BALAMT;
        $creditAccount->balanceDate = $this->createDateTimeFromStr($xml->CCSTMTRS->LEDGERBAL->DTASOF, true);

        $creditAccount->statement = new Statement();
        $creditAccount->statement->currency = $xml->CCSTMTRS->CURDEF;
        $creditAccount->statement->startDate = $this->createDateTimeFromStr($xml->CCSTMTRS->BANKTRANLIST->DTSTART);
        $creditAccount->statement->endDate = $this->createDateTimeFromStr($xml->CCSTMTRS->BANKTRANLIST->DTEND);
        $creditAccount->statement->transactions = $this->buildTransactions($xml->CCSTMTRS->BANKTRANLIST->STMTTRN);

        return $creditAccount;
    }

    private function buildTransactions(\SimpleXMLElement $transactions)
    {
        $return = [];
        foreach ($transactions as $t) {
            $Transaction = new Transaction();
            $Transaction->type = (string)$t->TRNTYPE;
            $Transaction->date = $this->createDateTimeFromStr($t->DTPOSTED);
            $Transaction->amount = $this->createAmountFromStr($t->TRNAMT);
            $Transaction->uniqueId = (string)$t->FITID;
            $Transaction->name = (string)$t->NAME;
            $Transaction->memo = (string)$t->MEMO;
            $Transaction->sic = $t->SIC;
            $Transaction->checkNumber = $t->CHECKNUM;
            $return[] = $Transaction;
        }

        return $return;
    }

    private function buildStatus($xml)
    {
        $Status = new Status();
        $Status->code = $xml->CODE;
        $Status->severity = $xml->SEVERITY;
        $Status->message = $xml->MESSAGE;

        return $Status;
    }

    /**
     * Create a DateTime object from a valid OFX date format
     *
     * Supports:
     * YYYYMMDDHHMMSS.XXX[gmt offset:tz name]
     * YYYYMMDDHHMMSS.XXX
     * YYYYMMDDHHMMSS
     * YYYYMMDD
     *
     * @param  string $dateString
     * @param  boolean $ignoreErrors
     * @return \DateTime $dateString
     * @throws \Exception
     */
    private function createDateTimeFromStr($dateString, $ignoreErrors = false)
    {
        $regex = '/'
            . "(\d{4})(\d{2})(\d{2})?"     // YYYYMMDD             1,2,3
            . "(?:(\d{2})(\d{2})(\d{2}))?" // HHMMSS   - optional  4,5,6
            . "(?:\.(\d{3}))?"             // .XXX     - optional  7
            . "(?:\[(-?\d+)\:(\w{3}\]))?"  // [-n:TZ]  - optional  8,9
            . '/';

        if (preg_match($regex, $dateString, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            $hour = isset($matches[4]) ? $matches[4] : 0;
            $min = isset($matches[5]) ? $matches[5] : 0;
            $sec = isset($matches[6]) ? $matches[6] : 0;

            $format = $year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $min . ':' . $sec;

            try {
                return new \DateTime($format);
            } catch (\Exception $e) {
                if ($ignoreErrors) {
                    return null;
                }

                throw $e;
            }
        }

        throw new \RuntimeException('Failed to initialize DateTime for string: ' . $dateString);
    }

    /**
     * Create a formatted number in Float according to different locale options
     *
     * Supports:
     * 000,00 and -000,00
     * 0.000,00 and -0.000,00
     * 0,000.00 and -0,000.00
     * 000.00 and 000.00
     *
     * @param  string $amountString
     * @return float
     */
    private function createAmountFromStr($amountString)
    {
        // Decimal mark style (UK/US): 000.00 or 0,000.00
        if (preg_match('/^-?([\d,]+)(\.?)([\d]{2})$/', $amountString) === 1) {
            return (float)preg_replace(
                ['/([,]+)/', '/\.?([\d]{2})$/'],
                ['', '.$1'],
                $amountString
            );
        }

        // European style: 000,00 or 0.000,00
        if (preg_match('/^-?([\d\.]+,?[\d]{2})$/', $amountString) === 1) {
            return (float)preg_replace(
                ['/([\.]+)/', '/,?([\d]{2})$/'],
                ['', '.$1'],
                $amountString
            );
        }

        return (float)$amountString;
    }
}
