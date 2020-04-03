<?php
/**
 * Sephpa
 *
 * @license   GNU LGPL v3.0 - For details have a look at the LICENSE file
 * @copyright ©2018 Alexander Schickedanz
 * @link      https://github.com/AbcAeffchen/Sephpa
 *
 * @author  Alexander Schickedanz <abcaeffchen@gmail.com>
 */

namespace AbcAeffchen\Sephpa\PaymentCollections;
use AbcAeffchen\SepaUtilities\SepaUtilities;
use AbcAeffchen\Sephpa\SephpaInputException;

/**
 * Manages direct debits
 */
class SepaDirectDebit00800102Ch03 extends SepaDirectDebitCollection
{
    /**
     * @type int VERSION The SEPA file version of this collection
     */
    const VERSION = SepaUtilities::SEPA_PAIN_008_001_02_CH_03;

    /**
     * @param mixed[] $debitInfo        Needed keys: 'pmtInfId', 'lclInstrm', 'seqTp', 'cdtr',
     *                                  'iban', 'bic', 'ci'; optional keys: 'ccy', 'btchBookg',
     *                                  'ctgyPurp', 'ultmtCdtr', 'reqdColltnDt'
     * @param bool    $checkAndSanitize All inputs will be checked and sanitized before creating
     *                                  the collection. If you check the inputs yourself you can
     *                                  set this to false.
     * @param int     $flags            The flags used for sanitizing
     * @throws SephpaInputException
     */
    public function __construct(array $debitInfo, $checkAndSanitize = true, $flags = 0)
    {
        $this->today = (int) date('Ymd');
        $this->checkAndSanitize = $checkAndSanitize;
        $this->sanitizeFlags = $flags;

        if(!SepaUtilities::checkRequiredCollectionKeys($debitInfo, self::VERSION) )
            throw new SephpaInputException('One of the required inputs \'pmtInfId\', \'lclInstrm\', \'seqTp\', \'cdtr\', \'iban\', \'ci\' is missing.');

        if($this->checkAndSanitize)
        {
            $checkResult = SepaUtilities::checkAndSanitizeAll($debitInfo, $this->sanitizeFlags, ['version' => self::VERSION]);

            if($checkResult !== true)
                throw new SephpaInputException('The values of ' . $checkResult . ' are invalid.');

            // IBAN and BIC can belong to each other?
            if(!empty($debitInfo['bic']) && !SepaUtilities::crossCheckIbanBic($debitInfo['iban'], $debitInfo['bic']))
                throw new SephpaInputException('IBAN and BIC do not belong to each other.');
        }

        $this->debitInfo = $debitInfo;
    }

    /**
     * calculates the sum of all payments in this collection
     *
     * @param mixed[] $paymentInfo needed keys: 'pmtId', 'instdAmt', 'mndtId', 'dtOfSgntr', 'bic',
     *                             'dbtr', 'iban';
     *                             optional keys: 'amdmntInd', 'orgnlMndtId', 'orgnlCdtrSchmeId_nm',
     *                             'orgnlCdtrSchmeId_id', 'orgnlDbtrAcct_iban', 'orgnlDbtrAgt',
     *                             'elctrncSgntr', 'ultmtDbtr', 'purp', 'rmtInf'
     * @throws SephpaInputException
     * @return void
     */
    public function addPayment(array $paymentInfo)
    {
        if($this->checkAndSanitize)
        {
            if(!SepaUtilities::checkRequiredPaymentKeys($paymentInfo, self::VERSION) )
                throw new SephpaInputException('One of the required inputs \'pmtId\', \'instdAmt\', \'mndtId\', \'dtOfSgntr\', \'dbtr\', \'iban\' is missing.');

            $bicRequired = (!SepaUtilities::isEEATransaction($this->cdtrIban,$paymentInfo['iban']));

            $checkResult = SepaUtilities::checkAndSanitizeAll($paymentInfo, $this->sanitizeFlags,
                                                              ['allowEmptyBic' => $bicRequired, 'version' => self::VERSION]);

            if($checkResult !== true)
                throw new SephpaInputException('The values of ' . $checkResult . ' are invalid.');

            if( !empty( $paymentInfo['amdmntInd'] ) && $paymentInfo['amdmntInd'] === 'true' )
            {

                if( SepaUtilities::containsNotAnyKey($paymentInfo, ['orgnlMndtId',
                                                                    'orgnlCdtrSchmeId_nm',
                                                                    'orgnlCdtrSchmeId_id',
                                                                    'orgnlDbtrAcct_iban',
                                                                    'orgnlDbtrAgt'])
                )
                    throw new SephpaInputException('You set \'amdmntInd\' to \'true\', so you have to set also at least one of the following inputs: \'orgnlMndtId\', \'orgnlCdtrSchmeId_nm\', \'orgnlCdtrSchmeId_id\', \'orgnlDbtrAcct_iban\', \'orgnlDbtrAgt\'.');

                if( !empty( $paymentInfo['orgnlDbtrAgt'] ) && $paymentInfo['orgnlDbtrAgt'] === 'SMNDA' && $this->debitInfo['seqTp'] !== SepaUtilities::SEQUENCE_TYPE_RECURRING && $this->debitInfo['seqTp'] !== SepaUtilities::SEQUENCE_TYPE_FIRST)
                    throw new SephpaInputException('You set \'amdmntInd\' to \'true\' and \'orgnlDbtrAgt\' to \'SMNDA\', \'seqTp\' has to be \'' . SepaUtilities::SEQUENCE_TYPE_FIRST . '\' or \'' . SepaUtilities::SEQUENCE_TYPE_RECURRING . '\'.');

            }

            // IBAN and BIC can belong to each other?
            if(!empty($paymentInfo['bic']) && !SepaUtilities::crossCheckIbanBic($paymentInfo['iban'],$paymentInfo['bic']))
                throw new SephpaInputException('IBAN and BIC do not belong to each other.');
        }

        // adjustments
        // local instrument COR1 got included into CORE.
        if($this->debitInfo['lclInstrm'] === SepaUtilities::LOCAL_INSTRUMENT_CORE_DIRECT_DEBIT_D_1)
            $this->debitInfo['lclInstrm'] = SepaUtilities::LOCAL_INSTRUMENT_CORE_DIRECT_DEBIT;

        // it is no longer required to use FRST as sequence type for the first direct debit
        // instead it is recommended to use RCUR
        if($this->debitInfo['seqTp'] === SepaUtilities::SEQUENCE_TYPE_FIRST)
            $this->debitInfo['seqTp'] = SepaUtilities::SEQUENCE_TYPE_RECURRING;

        $this->payments[] = $paymentInfo;
    }

    /**
     * Generates the xml for the collection using generatePaymentXml
     *
     * @param \SimpleXMLElement $pmtInf The PmtInf-Child of the xml object
     * @return void
     */
    public function generateCollectionXml(\SimpleXMLElement $pmtInf)
    {
        $ccy = empty( $this->debitInfo['ccy'] ) ? self::CCY : $this->debitInfo['ccy'];

        $datetime     = new \DateTime();
        $reqdColltnDt = ( !empty( $this->debitInfo['reqdColltnDt'] ) )
            ? $this->debitInfo['reqdColltnDt'] : $datetime->format('Y-m-d');

        $pmtInf->addChild('PmtInfId', $this->debitInfo['pmtInfId']);
        $pmtInf->addChild('PmtMtd', 'DD');
        if( !empty( $this->debitInfo['btchBookg'] ) )
            $pmtInf->addChild('BtchBookg', $this->debitInfo['btchBookg']);
//        $pmtInf->addChild('NbOfTxs', $this->getNumberOfTransactions());
//        $pmtInf->addChild('CtrlSum', sprintf('%01.2F', $this->getCtrlSum()));

        $pmtTpInf = $pmtInf->addChild('PmtTpInf');
        $pmtTpInf->addChild('SvcLvl')->addChild('Prtry', 'CHTA');
        $pmtTpInf->addChild('LclInstrm')->addChild('Prtry', $this->debitInfo['lclInstrm']);

        $pmtInf->addChild('ReqdColltnDt', $reqdColltnDt);
        $pmtInf->addChild('Cdtr')->addChild('Nm', $this->debitInfo['cdtr']);

        $cdtrAcct = $pmtInf->addChild('CdtrAcct');
        $cdtrAcct->addChild('Id')->addChild('IBAN', $this->debitInfo['iban']);
//        $cdtrAcct->addChild('Ccy', $ccy);

        $finInstnId = $pmtInf->addChild('CdtrAgt')->addChild('FinInstnId');
        $finInstnId->addChild('ClrSysMmbId')->addChild('MmbId', $this->debitInfo['mmbid']);
        $finInstnId->addChild('Othr')->addChild('Id', $this->debitInfo['esr']);


        if( !empty( $this->debitInfo['ultmtCdtr'] ) )
            $pmtInf->addChild('UltmtCdtr')->addChild('Nm', $this->debitInfo['ultmtCdtr']);


        $ci = $pmtInf->addChild('CdtrSchmeId')->addChild('Id')->addChild('PrvtId')
                     ->addChild('Othr');
        $ci->addChild('Id', $this->debitInfo['lsv']);
        $ci->addChild('SchmeNm')->addChild('Prtry', 'CHLS');

        foreach($this->payments as $payment)
        {
            $drctDbtTxInf = $pmtInf->addChild('DrctDbtTxInf');
            $this->generatePaymentXml($drctDbtTxInf, $payment, $ccy);
        }
    }

    /**
     * Generates the xml for a single payment
     *
     * @param \SimpleXMLElement $drctDbtTxInf
     * @param mixed[]           $payment One of the payments in $this->payments
     * @param string            $ccy     currency
     * @return void
     */
    private function generatePaymentXml(\SimpleXMLElement $drctDbtTxInf, $payment, $ccy)
    {
        $drctDbtTxInf->addChild('PmtId')->addChild('EndToEndId', $payment['pmtId']);
        $drctDbtTxInf->addChild('PmtId')->addChild('InstrId', $payment['pmtId']);
        $drctDbtTxInf->addChild('InstdAmt', sprintf('%01.2F', $payment['instdAmt']))
                     ->addAttribute('Ccy', $ccy);

//        $mndtRltdInf = $drctDbtTxInf->addChild('DrctDbtTx')->addChild('MndtRltdInf');
//        $mndtRltdInf->addChild('MndtId', $payment['mndtId']);
//        $mndtRltdInf->addChild('DtOfSgntr', $payment['dtOfSgntr']);
//        if(!empty($payment['amdmntInd']))
//        {
//            $mndtRltdInf->addChild('AmdmntInd', $payment['amdmntInd']);
//            if( $payment['amdmntInd'] === 'true' )
//            {
//                $amdmntInd = $mndtRltdInf->addChild('AmdmntInfDtls');
//                if( !empty( $payment['orgnlMndtId'] ) )
//                    $amdmntInd->addChild('OrgnlMndtId', $payment['orgnlMndtId']);
//                if( !empty( $payment['orgnlCdtrSchmeId_Nm'] ) || !empty( $payment['orgnlCdtrSchmeId_Id'] ) )
//                {
//                    $orgnlCdtrSchmeId = $amdmntInd->addChild('OrgnlCdtrSchmeId');
//                    if( !empty( $payment['orgnlCdtrSchmeId_Nm'] ) )
//                        $orgnlCdtrSchmeId->addChild('Nm', $payment['orgnlCdtrSchmeId_Nm']);
//                    if( !empty( $payment['orgnlCdtrSchmeId_Id'] ) )
//                    {
//                        $othr = $orgnlCdtrSchmeId->addChild('Id')->addChild('PrvtId')
//                                                 ->addChild('Othr');
//                        $othr->addChild('Id', $payment['orgnlCdtrSchmeId_Id']);
//                        $othr->addChild('SchmeNm')->addChild('Prtry', 'SEPA');
//                    }
//                }
//                if( !empty( $payment['orgnlDbtrAcct_iban'] ) )
//                    $amdmntInd->addChild('OrgnlDbtrAcct')->addChild('Id')
//                              ->addChild('IBAN', $payment['orgnlDbtrAcct_iban']);
//                elseif( !empty( $payment['orgnlDbtrAgt_bic'] ) )
//                    $amdmntInd->addChild('OrgnlDbtrAgt')->addChild('FinInstnId')
//                              ->addChild('BIC', $payment['orgnlDbtrAgt_bic']);
//                else
//                    $amdmntInd->addChild('OrgnlDbtrAcct')->addChild('Othr')
//                              ->addChild('Id', 'SMNDA');
//            }
//        }
//        if( !empty( $payment['elctrncSgntr'] ) )
//            $mndtRltdInf->addChild('ElctrncSgntr', $payment['elctrncSgntr']);
//
//        if( !empty( $payment['bic'] ) )
//            $drctDbtTxInf->addChild('DbtrAgt')->addChild('FinInstnId')
//                   ->addChild('BIC', $payment['bic']);
//        else
//            $drctDbtTxInf->addChild('DbtrAgt')->addChild('FinInstnId')->addChild('Othr')
//                   ->addChild('Id', 'NOTPROVIDED');

        $drctDbtTxInf->addChild('DbtrAgt')->addChild('FinInstnId')->addChild('ClrSysMmbId')
            ->addChild('MmbId', '09000');

        $drctDbtTxInf->addChild('Dbtr')->addChild('Nm', $payment['dbtr']);
        $drctDbtTxInf->addChild('DbtrAcct')->addChild('Id')
                     ->addChild('IBAN', $payment['iban']);
        if( !empty( $payment['ultmtDbtr'] ) )
            $drctDbtTxInf->addChild('UltmtDbtr')->addChild('Nm', $payment['ultmtDbtr']);
        if( !empty( $payment['purp'] ) )
            $drctDbtTxInf->addChild('Purp')->addChild('Cd', $payment['purp']);

        $rmtInf = $drctDbtTxInf->addChild('RmtInf');
        if( !empty( $payment['rmtInf'] ) )
        {
            $rmtInf->addChild('Ustrd', $payment['rmtInf']);
        }

        $cdtrRefInf = $rmtInf->addChild('Strd')->addChild('CdtrRefInf');
        $cdtrRefInf->addChild('Tp')->addChild('CdOrPrtry')->addChild('Prtry','ESR');
        $cdtrRefInf->addChild('Ref','123456789123456789123456789');
    }
}
