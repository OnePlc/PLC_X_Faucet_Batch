<?php
/**
 * BatchController.php - Main Controller
 *
 * Batch Controller Faucet Module
 *
 * @category Controller
 * @package Faucet\Batch
 * @author Verein onePlace
 * @copyright (C) 2020  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

declare(strict_types=1);

namespace OnePlace\Faucet\Batch\Controller;

use Application\Controller\CoreEntityController;
use Application\Model\CoreEntityModel;
use Laminas\Http\Client;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\Sql\Expression;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Http\ClientStatic;
use OnePlace\User\Model\UserTable;

class BatchController extends CoreEntityController
{
    /**
     * Faucet Table Object
     *
     * @since 1.0.0
     */
    protected $oTableGateway;

    protected $achievDoneTbl;
    protected $achievTbl;
    protected $userSetTbl;

    /**
     * FaucetController constructor.
     *
     * @param AdapterInterface $oDbAdapter
     * @param FaucetTable $oTableGateway
     * @since 1.0.0
     */
    public function __construct(AdapterInterface $oDbAdapter,UserTable $oTableGateway,$oServiceManager)
    {
        $this->oTableGateway = $oTableGateway;
        $this->sSingleForm = 'faucet-batch-single';

        if(isset(CoreEntityController::$oSession->oUser)) {
            setlocale(LC_TIME, CoreEntityController::$oSession->oUser->lang);
        }

        parent::__construct($oDbAdapter,$oTableGateway,$oServiceManager);

        if($oTableGateway) {
            # Attach TableGateway to Entity Models
            if(!isset(CoreEntityModel::$aEntityTables[$this->sSingleForm])) {
                CoreEntityModel::$aEntityTables[$this->sSingleForm] = $oTableGateway;
            }
        }

        $this->achievDoneTbl = new TableGateway('faucet_achievement_user', CoreEntityController::$oDbAdapter);
        $this->achievTbl = new TableGateway('faucet_achievement', CoreEntityController::$oDbAdapter);
        $this->userSetTbl = new TableGateway('user_setting', CoreEntityController::$oDbAdapter);
    }

    /**
     * Placeholder Welcome Batch
     *
     * @return mixed
     * @since 1.0.0
     */
    public function indexAction()
    {
        $this->layout('layout/json');


        echo 'Welcome to Faucet Batch Server';

        return false;
    }

    public function halloffameAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $oStatsTbl = $this->getCustomTable('faucet_statistic');
        $oAdsTbl = $this->getCustomTable('ptc');
        $oAdsDoneTbl = $this->getCustomTable('ptc_user');
        $oProvidersTbl = $this->getCustomTable('shortlink');
        $oMyLinksDoneTbl = $this->getCustomTable('shortlink_link_user');
        $oWallsDoneTbl = $this->getCustomTable('offerwall_user');
        $oClaimTbl = $this->getCustomTable('faucet_claim');
        $oGameTbl = $this->getCustomTable('faucet_game_match');
        $oMinerTbl = $this->getCustomTable('faucet_miner');

        $aIncomeByUserID = [];

        $oWhPTC = new Where();
        $oWhPTC->greaterThanOrEqualTo('date_claimed', date('Y-m-d H:i:s', strtotime('first day of this month')));
        $oPTCDone = $oAdsDoneTbl->select($oWhPTC)->current();
        $aPTCRewards = [];
        $oPTCInfoDB = $oAdsTbl->select();
        foreach($oPTCInfoDB as $oPTCInfo) {
            $aPTCRewards[$oPTCInfo->PTC_ID] = $oPTCInfo->reward;
        }
        foreach($oPTCDone as $oPTC) {
            if(!array_key_exists($oPTC->user_idfs,$aIncomeByUserID)) {
                $aIncomeByUserID[$oPTC->user_idfs] = 0;
            }
            if(array_key_exists($oPTC->ptc_idfs,$aPTCRewards)) {
                $aIncomeByUserID[$oPTC->user_idfs]+=$aPTCRewards[$oPTC->user_idfs];
            }
        }

        $oWhSh = new Where();
        $oWhSh->greaterThanOrEqualTo('date_claimed', date('Y-m-d H:i:s', strtotime('first day of this month')));
        $oShDone = $oMyLinksDoneTbl->select($oWhSh);
        $aShRewards = [];
        $oShInfoDB = $oProvidersTbl->select();
        foreach($oShInfoDB as $oShInfo) {
            $aShRewards[$oShInfo->Shortlink_ID] = $oShInfo->reward;
        }
        foreach($oShDone as $oSh) {
            if(!array_key_exists($oSh->user_idfs,$aIncomeByUserID)) {
                $aIncomeByUserID[$oSh->user_idfs] = 0;
            }
            if(array_key_exists($oSh->shortlink_idfs,$aShRewards)) {
                $aIncomeByUserID[$oSh->user_idfs]+=$aShRewards[$oSh->shortlink_idfs];
            }
        }

        $oWhOff = new Where();
        $oWhOff->greaterThanOrEqualTo('date_completed', date('Y-m-d H:i:s', strtotime('first day of this month')));
        $oOffDone = $oWallsDoneTbl->select($oWhOff);
        foreach($oOffDone as $oOff) {
            if(!array_key_exists($oOff->user_idfs,$aIncomeByUserID)) {
                $aIncomeByUserID[$oOff->user_idfs] = 0;
            }
            $aIncomeByUserID[$oOff->user_idfs]+=$oOff->amount;
        }

        $oWhClaim = new Where();
        $oWhClaim->greaterThanOrEqualTo('date', date('Y-m-d H:i:s', strtotime('first day of this month')));
        $oClaimDone = $oClaimTbl->select($oWhClaim);
        foreach($oClaimDone as $oCl) {
            if(!array_key_exists($oCl->user_idfs,$aIncomeByUserID)) {
                $aIncomeByUserID[$oCl->user_idfs] = 0;
            }
            $aIncomeByUserID[$oCl->user_idfs]+=$oCl->amount;
        }

        $oWhGame = new Where();
        $oWhGame->greaterThanOrEqualTo('date_matched', date('Y-m-d H:i:s', strtotime('first day of this month')));
        $oGameDone = $oGameTbl->select($oWhGame);
        foreach($oGameDone as $oGame) {
            if($oGame->winner_idfs == 0) {
                continue;
            }
            if(!array_key_exists($oGame->winner_idfs,$aIncomeByUserID)) {
                $aIncomeByUserID[$oGame->winner_idfs] = 0;
            }
            $aIncomeByUserID[$oGame->winner_idfs]+=$oGame->amount_bet;
        }

        $oWhMine = new Where();
        $oWhMine->greaterThanOrEqualTo('date', date('Y-m-d H:i:s', strtotime('first day of this month')));
        $oMineDone = $oMinerTbl->select($oWhMine);
        foreach($oMineDone as $oMine) {
            if($oMine->amount_coin == 0) {
                continue;
            }
            if(!array_key_exists($oMine->user_idfs,$aIncomeByUserID)) {
                $aIncomeByUserID[$oMine->user_idfs] = 0;
            }
            $aIncomeByUserID[$oMine->user_idfs]+=$oMine->amount_coin;
        }

        arsort($aIncomeByUserID);

        $aTopEarners = [];

        $iCount = 1;
        foreach(array_keys($aIncomeByUserID) as $iUsrID) {
            if($iCount == 6) {
                break;
            }
            $aTopEarners[$iCount] = (object)['id' => $iUsrID,'coins' => $aIncomeByUserID[$iUsrID]];
            $iCount++;
        }

        $oCheckWh = new Where();
        $oCheckWh->like('stat-key', 'topearners-daily');
        $oCheckWh->like('date', date('Y-m-d', time()).'%');
        $oStatsCheck = $oStatsTbl->select($oCheckWh);

        if(count($oStatsCheck) == 0) {
            $oStatsTbl->insert([
                'stat-key' => 'topearners-daily',
                'date' => date('Y-m-d H:i:s', time()),
                'stat-data' => json_encode($aTopEarners),
            ]);
        } else {
            $oStatsTbl->update([
                'stat-data' => json_encode($aTopEarners),
                'date' => date('Y-m-d H:i:s', time()),
            ],$oCheckWh);
        }

        echo 'Gen Stats';

        return false;
    }

    /**
     * Get Balances from Nanopool and Credit Coins
     *
     * @return false
     * @since 1.0.0
     */
    public function getminerbalancesAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $sApiINfo = file_get_contents(CoreEntityController::$aGlobalSettings['miner-pool-url']);
        $oApiData = json_decode($sApiINfo);

        $oMinerTbl = $this->getCustomTable('faucet_miner');
        $oUsrTbl = $this->getCustomTable('user');

        if(isset($oApiData->data)) {
            $fTotalHash = 0;
            foreach($oApiData->data as $oW) {
                $bIsFaucetMiner = stripos($oW->id,'swissfaucetio');
                if($bIsFaucetMiner === false) {
                    # ignore
                } else {
                    $iUserID = substr($oW->id,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }
                    if($oMinerUser) {
                        $iCurrentShares = $oW->rating;

                        $oLastEntryWh = new Where();
                        $oLastEntryWh->equalTo('user_idfs', $oMinerUser->getID());
                        $oLastEntryWh->like('coin', 'rvn');

                        $oLastSel = new Select($oMinerTbl->getTable());
                        $oLastSel->where($oLastEntryWh);
                        $oLastSel->order('date DESC');
                        $oLastSel->limit(1);

                        if($iCurrentShares < 0) {
                            $iCurrentShares = 0;
                        }

                        $fCoins = 0;
                        $oLastEntry = $oMinerTbl->selectWith($oLastSel);
                        if(count($oLastEntry) == 0) {
                            $fCoins = round((float)($iCurrentShares/1000)*2000,2);
                            $oMinerTbl->insert([
                                'user_idfs' => $oMinerUser->getID(),
                                'rating' => $iCurrentShares,
                                'shares' => $iCurrentShares,
                                'amount_coin' => $fCoins,
                                'date' => date('Y-m-d H:i:s', time()),
                                'coin' => 'rvn',
                                'pool' => 'nanopool',
                            ]);
                            echo 'miner added';
                        } else {
                            $oLastEntry = $oLastEntry->current();
                            $iNewShares = $iCurrentShares-$oLastEntry->rating;
                            $fCoins = 0;
                            if($iNewShares > 0) {
                                $fCoins = round((float)($iNewShares/1000)*2000,2);
                            } else {
                                $iNewShares = 0;
                            }

                            $oMinerTbl->insert([
                                'user_idfs' => $oMinerUser->getID(),
                                'rating' => $iCurrentShares,
                                'shares' => $iNewShares,
                                'amount_coin' => $fCoins,
                                'date' => date('Y-m-d H:i:s', time()),
                                'coin' => 'rvn',
                                'pool' => 'nanopool',
                            ]);

                            echo 'miner updated';
                        }
                        $fTotalHash+=$oW->hashrate;
                        if($fCoins > 0) {
                            $fCurrentBalance = $oUsrTbl->select(['User_ID' => $oMinerUser->getID()])->current()->token_balance;
                            $oUsrTbl->update([
                                'token_balance' => $fCurrentBalance+$fCoins,
                            ],'User_ID = '.$oMinerUser->getID());
                        }
                    }
                }
            }

            $oSetTbl = $this->getCustomTable('settings');
            $oSetTbl->update(['settings_value' => round($fTotalHash,2)],['settings_key' => 'faucetminer-totalhash']);
        }

        return false;
    }

    public function statsAction()
    {
        if (isset($_REQUEST['authkey'])) {
            if (strip_tags($_REQUEST['authkey']) == CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $this->layout('layout/json');

                $oUserTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);
                $oUserMetricsTbl = new TableGateway('core_metric', CoreEntityController::$oDbAdapter);

                $aUsersActive = $oUserTbl->select(['theme' => 'faucet']);
                $bTotalBalance = 0;
                foreach ($aUsersActive as $oUsr) {
                    $bTotalBalance += $oUsr->token_balance;
                }

                $oStatsTbl = new TableGateway('core_statistic', CoreEntityController::$oDbAdapter);

                $oWh = new Where();
                $oWh->like('stats_key', 'tokenbalance-daily');
                $oWh->like('date', date('Y-m-d', time()) . '%');
                $oCheck = $oStatsTbl->select($oWh);
                if (count($oCheck) == 0) {
                    $oStatsTbl->insert([
                        'stats_key' => 'tokenbalance-daily',
                        'data' => $bTotalBalance,
                        'date' => date('Y-m-d H:i:s', time()),
                    ]);
                } else {
                    $oStatsTbl->update([
                        'data' => $bTotalBalance,
                        'date' => date('Y-m-d H:i:s', time()),
                    ], $oWh);
                }

                $oWh = new Where();
                $oWh->like('stats_key', 'userstats-daily');
                $oWh->like('date', date('Y-m-d', time()) . '%');
                $oCheck = $oStatsTbl->select($oWh);
                if (count($oCheck) == 0) {
                    $oStatsTbl->insert([
                        'stats_key' => 'userstats-daily',
                        'data' => count($aUsersActive),
                        'date' => date('Y-m-d H:i:s', time()),
                    ]);
                } else {
                    $oStatsTbl->update([
                        'data' => count($aUsersActive),
                        'date' => date('Y-m-d H:i:s', time()),
                    ], $oWh);
                }

                $oWh = new Where();
                $oWh->like('action', 'login');
                $oWh->like('type', 'success');
                $oWh->like('date', date('Y-m-d', time()) . '%');
                $oLoginsToday = $oUserMetricsTbl->select($oWh);
                $iLoginsToday = count($oLoginsToday);

                $oWh = new Where();
                $oWh->like('stats_key', 'logins-daily');
                $oWh->like('date', date('Y-m-d', time()) . '%');
                $oCheck = $oStatsTbl->select($oWh);
                if (count($oCheck) == 0) {
                    $oStatsTbl->insert([
                        'stats_key' => 'logins-daily',
                        'data' => $iLoginsToday,
                        'date' => date('Y-m-d H:i:s', time()),
                    ]);
                } else {
                    $oStatsTbl->update([
                        'data' => $iLoginsToday,
                        'date' => date('Y-m-d H:i:s', time()),
                    ], $oWh);
                }

                echo 'done';

                return false;
            }
        }

        return $this->redirect()->toRoute('home');
    }

    public function fetchcmcdataAction()
    {
        $this->layout('layout/json');
        if (isset($_REQUEST['authkey'])) {
            if (strip_tags($_REQUEST['authkey']) == CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $this->layout('layout/json');

                $url = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency/quotes/latest';
                $parameters = [
                    'slug' => 'bitcoin,ethereum,ethereum-classic,ravencoin,groestlcoin,bitcoin-cash,dogecoin,binance-coin,litecoin,horizen',
                ];

                $headers = [
                    'Accepts: application/json',
                    'X-CMC_PRO_API_KEY: '.CoreEntityController::$aGlobalSettings['cmc-api-key'],
                ];
                $qs = http_build_query($parameters); // query string encode the parameters
                $request = "{$url}?{$qs}"; // create the request URL

                $curl = curl_init(); // Get cURL resource
                // Set cURL options
                curl_setopt_array($curl, array(
                    CURLOPT_URL => $request,            // set the request URL
                    CURLOPT_HTTPHEADER => $headers,     // set the headers
                    CURLOPT_RETURNTRANSFER => 1         // ask for raw response instead of bool
                ));

                $response = curl_exec($curl); // Send the request, save the response
                //print_r(json_decode($response)); // print json decoded response
                curl_close($curl); // Close request

                $oJson = json_decode($response);
                if ($oJson->status->error_code == 0) {
                    $oWallTbl = $this->getCustomTable('faucet_wallet');

                    foreach ($oJson->data as $oCoin) {
                        $sName = $oCoin->name;
                        $fCurrentPrice = $oCoin->quote->USD->price;
                        $fChange24h = $oCoin->quote->USD->percent_change_24h;

                        if (is_numeric($fCurrentPrice) && $fCurrentPrice != '') {
                            $oWallTbl->update([
                                'dollar_val' => (float)$fCurrentPrice,
                                'change_24h' => (float)$fChange24h,
                                'last_update' => date('Y-m-d H:i:s', time()),
                            ], ['coin_sign' => $oCoin->symbol]);
                        }
                    }

                    echo 'price update done';
                } else {
                    echo 'got error: ' . $oJson->status->error_code;
                }

                return false;
            }
        }

        echo 'done';
        return false;
    }

    public function fetchwebminerbalancesAction()
    {
        if (isset($_REQUEST['authkey'])) {
            if (strip_tags($_REQUEST['authkey']) == CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $this->layout('layout/json');

                $oMinerTbl = $this->getCustomTable('faucet_miner');
                $oUsrTbl = $this->getCustomTable('user');
                $sBaseUrl = 'https://webminepool.com/api/'.CoreEntityController::$aGlobalSettings['webminepool-apikey'];

                $sCall = $sBaseUrl.'/users/';

                $sJson = file_get_contents($sCall);

                $oJson = json_decode($sJson);

                if(count($oJson->users) > 0) {
                    foreach($oJson->users as $oUsr) {
                        $oMinerUser = false;
                        echo 'check user .'.$oUsr->name;
                        if(is_numeric($oUsr->name)) {
                            try {
                                $oMinerUser = $this->oTableGateway->getSingle($oUsr->name);
                            } catch(\RuntimeException $e) {
                                # user not found - ignore
                            }
                        }
                        if($oMinerUser) {
                            $oLastEntryWh = new Where();
                            $oLastEntryWh->equalTo('user_idfs', $oMinerUser->getID());
                            $oLastEntryWh->like('coin', 'wmp');

                            $oLastSel = new Select($oMinerTbl->getTable());
                            $oLastSel->where($oLastEntryWh);
                            $oLastSel->order('date DESC');
                            $oLastSel->limit(1);

                            $fCoins = 0;
                            $oLastEntry = $oMinerTbl->selectWith($oLastSel);
                            if(count($oLastEntry) == 0) {
                                $fCoins = round((float)($oUsr->hashes/1000000)*15,2);
                                if($fCoins > 0) {
                                    $oMinerTbl->insert([
                                        'user_idfs' => $oMinerUser->getID(),
                                        'rating' => $oUsr->hashes,
                                        'shares' => $oUsr->hashes,
                                        'amount_coin' => $fCoins,
                                        'date' => date('Y-m-d H:i:s', time()),
                                        'coin' => 'wmp',
                                        'pool' => 'webminepool',
                                    ]);
                                    echo 'webminer added';
                                }

                            } else {
                                $oLastEntry = $oLastEntry->current();
                                $iNewShares = $oUsr->hashes-$oLastEntry->rating;
                                $fCoins = round((float)($iNewShares/1000000)*15,2);

                                if($fCoins > 0) {
                                    $oMinerTbl->insert([
                                        'user_idfs' => $oUsr->name,
                                        'rating' => $oUsr->hashes,
                                        'shares' => $iNewShares,
                                        'amount_coin' => $fCoins,
                                        'date' => date('Y-m-d H:i:s', time()),
                                        'coin' => 'wmp',
                                        'pool' => 'webminepool',
                                    ]);
                                }
                                echo 'webminer updated';
                            }
                            if($fCoins > 0) {
                                $fCurrentBalance = $oUsrTbl->select(['User_ID' => $oMinerUser->getID()])->current()->token_balance;
                                $oUsrTbl->update([
                                    'token_balance' => $fCurrentBalance+$fCoins,
                                ],'User_ID = '.$oMinerUser->getID());
                            }
                        }
                    }
                }

                return false;
            }
        }
    }

    /**
     * Parse E-Mail for Support inqueries
     *
     * @return false
     * @since 1.0.3
     */
    public function parsemailAction()
    {
        if (isset($_REQUEST['authkey'])) {
            if (strip_tags($_REQUEST['authkey']) == CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $this->layout('layout/json');

                $aMails = glob('/home/mailbatch/queue/*');

                $oRequestTbl = $this->getCustomTable('user_request');
                if(count($aMails) > 0) {
                    foreach($aMails as $sMail) {
                        $oReqCheck = $oRequestTbl->select([
                            'mail_name' => basename($sMail),
                        ]);
                        if(count($oReqCheck) > 0) {
                            echo 'mail already parsed';
                            continue;
                        }
                        $handle = fopen($sMail, "r");
                        if ($handle) {
                            $sContent = '';
                            $bContentStart = false;
                            while (($line = fgets($handle)) !== false) {
                                // process the line read.
                                // look out for special headers
                                if (preg_match("/^Subject: (.*)/", $line, $matches)) {
                                    $subject = $matches[1];
                                }
                                if (preg_match("/^From: (.*)/", $line, $matches)) {
                                    $from = $matches[1];
                                }
                                if (preg_match("/^To: (.*)/", $line, $matches)) {
                                    $to = $matches[1];
                                }
                                if($bContentStart) {
                                    $bEndContent = stripos($line, 'support@swissfaucet.io');
                                    if($bEndContent === false) {
                                        $bSkip = stripos($line, 'Content-Transfer-Encoding:');

                                        if($line != '' && $line != "\n" && $bSkip === false) {
                                            $sContent .= strip_tags($line);
                                        }
                                    } else {
                                        $bContentStart = false;
                                    }
                                }
                                if (preg_match("/^Content-Type: text\/plain(.*)/", $line, $matches)) {
                                    $bContentStart = true;
                                    echo 'start content';
                                }
                            }

                            fclose($handle);

                            $sEmailCheck = $from;
                            $bHasName = stripos($sEmailCheck,'<');
                            if($bHasName === false) {
                                echo $sEmailCheck;
                            } else {
                                $bMailEnd = stripos('>',$sEmailCheck);
                                $sEmailCheck = substr($sEmailCheck,$bHasName+1,strlen($sEmailCheck)-($bHasName+1)-1);

                                try {
                                    $oExistingOuser = $this->oTableGateway->getSingle($sEmailCheck, 'email');
                                    echo 'user found - add to support inbox';
                                    echo $sContent;

                                    $oReqCheck = $oRequestTbl->select([
                                        'user_idfs' => $oExistingOuser->getID(),
                                        'state' => 'new',
                                    ]);
                                    if(count($oReqCheck) == 0) {
                                        $oRequestTbl->insert([
                                            'user_idfs' => $oExistingOuser->getID(),
                                            'message' => $sContent,
                                            'name' => $oExistingOuser->getLabel(),
                                            'date' => date('Y-m-d H:i:s', time()),
                                            'state' => 'new',
                                            'reply' => '',
                                            'reply_user_idfs' => 0,
                                            'mail_name' => basename($sMail),
                                        ]);
                                    } else {
                                        # send email "you already have an open request please wait
                                    }
                                } catch(\RuntimeException $e) {
                                    echo 'user not found - add to default inbox';
                                }
                            }
                        } else {
                            // error opening the file.
                        }
                    }
                }
                return false;
            }
        }

        return $this->redirect()->toRoute('home');
    }

    public function appstatsAction()
    {
        $this->layout('layout/json');

        $oMetricTbl = $this->getCustomTable('core_metric');
        $aAppMetrics = [];
        $oMetricSel = new Select($oMetricTbl->getTable());
        $oMetricWh = new Where();
        $oMetricWh->like('action', 'app-%');
        $oMetricWh->greaterThanOrEqualTo('date', date('Y-m-d',time()).'%');
        $oMetricSel->where($oMetricWh);
        $oAppMetricsDB = $oMetricTbl->selectWith($oMetricSel);
        foreach($oAppMetricsDB as $oMet) {
            if(!array_key_exists($oMet->action,$aAppMetrics)) {
                $aAppMetrics[$oMet->action] = ['success' => 0,'error' => 0];
            }
            $aAppMetrics[$oMet->action][$oMet->type]++;
        }


        $oStatsTbl = new TableGateway('core_statistic', CoreEntityController::$oDbAdapter);
        $oCheckWh = new Where();
        $oCheckWh->like('stats_key', 'appmetrics-daily');
        $oCheckWh->like('date', date('Y-m-d', time()).'%');
        $oStatsCheck = $oStatsTbl->select($oCheckWh);

        if(count($oStatsCheck) == 0) {
            $oStatsTbl->insert([
                'stats_key' => 'appmetrics-daily',
                'date' => date('Y-m-d H:i:s', time()),
                'data' => json_encode($aAppMetrics),
            ]);
        } else {
            $oStatsTbl->update([
                'data' => json_encode($aAppMetrics),
                'date' => date('Y-m-d H:i:s', time()),
            ],$oCheckWh);
        }

        echo 'done';

        return false;
    }

    public function tokenstatsAction()
    {
        $this->layout('layout/json');

        $oStatsTbl = new TableGateway('core_statistic', CoreEntityController::$oDbAdapter);
        $oUsrTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);

        $rowset = $oUsrTbl->select(function(Select $select) {
            $select->columns(array(
                'sum' => new Expression('SUM(token_balance)')
            ));
            $select->where(array('theme' => 'faucet'));
        });
        $fBalance = $rowset->toArray()[0]['sum'];

        $rowset = $oUsrTbl->select(function(Select $select) {
            $oWh = new Where();
            $oWh->NEST
                ->notEqualTo('User_ID', 335874987)
                ->AND
                ->notEqualTo('User_ID', 335880436)
                ->AND
                ->notEqualTo('User_ID', 335877074)
                ->AND
                ->notEqualTo('User_ID', 335880700)
                ->AND
                ->notEqualTo('User_ID', 335875860)
                ->AND
                ->notEqualTo('User_ID', 335874988)
                ->AND
                ->notEqualTo('User_ID', 335875071)
                ->UNNEST;
            $oWh->like('theme', 'faucet');
            $select->columns(array(
                'sum' => new Expression('SUM(xp_level)')
            ));
            $select->where($oWh);
        });
        $fTotalLimitDB = $rowset->toArray()[0]['sum'];
        $fTotalLimit = 1000*(1+((($fTotalLimitDB)-1)/10));

        $rowset = $oUsrTbl->select(function(Select $select) {
            $oWh = new Where();
            $oWh->equalTo('User_ID', 1);
            $oWh->or->equalTo('User_ID', 335874987);
            $oWh->or->equalTo('User_ID', 335880436);
            $oWh->or->equalTo('User_ID', 335877074);
            $oWh->or->equalTo('User_ID', 335880700);
            $oWh->or->equalTo('User_ID', 335875860);
            $oWh->or->equalTo('User_ID', 335874988);
            $oWh->or->equalTo('User_ID', 335875071);

            $select->columns(array(
                'sum' => new Expression('SUM(token_balance)')
            ));
            $select->where($oWh);
        });
        $fBalanceAdmin = $rowset->toArray()[0]['sum'];

        $rowset = $oUsrTbl->select(function(Select $select) {
            $oWh = new Where();
            $oWh->NEST
                ->notEqualTo('User_ID', 335874987)
                ->AND
                ->notEqualTo('User_ID', 335880436)
                ->AND
                ->notEqualTo('User_ID', 335877074)
                ->AND
                ->notEqualTo('User_ID', 335880700)
                ->AND
                ->notEqualTo('User_ID', 335875860)
                ->AND
                ->notEqualTo('User_ID', 335874988)
                ->AND
                ->notEqualTo('User_ID', 335875071)
                ->UNNEST;
            $oWh->lessThanOrEqualTo('token_balance', 500);

            $select->columns(array(
                'sum' => new Expression('SUM(token_balance)')
            ));
            $select->where($oWh);
        });
        $fBalanceSmall = $rowset->toArray()[0]['sum'];

        $rowset = $oUsrTbl->select(function(Select $select) {
            $oWh = new Where();
            $oWh->NEST
                ->notEqualTo('User_ID', 335874987)
                ->AND
                ->notEqualTo('User_ID', 335880436)
                ->AND
                ->notEqualTo('User_ID', 335877074)
                ->AND
                ->notEqualTo('User_ID', 335880700)
                ->AND
                ->notEqualTo('User_ID', 335875860)
                ->AND
                ->notEqualTo('User_ID', 335874988)
                ->AND
                ->notEqualTo('User_ID', 335875071)
                ->UNNEST;
            $oWh->greaterThanOrEqualTo('token_balance', 1000);

            $select->columns(array(
                'sum' => new Expression('SUM(token_balance)')
            ));
            $select->where($oWh);
        });
        $fBalanceReady = $rowset->toArray()[0]['sum'];

        $oSetTbl = new TableGateway('user_setting', CoreEntityController::$oDbAdapter);
        $oMinerPayments = $oSetTbl->select(['setting_name' => 'mining-weekly-address']);

        $oUsrTbl = $this->oTableGateway;
        $fMinerBalances = 0;
        if(count($oMinerPayments) > 0) {
            foreach($oMinerPayments as $oPay) {
                $oPayer = $oUsrTbl->getSingle($oPay->user_idfs);
                $fMinerBalances+=$oPayer->token_balance;
            }
        }

        $oWthTbl =  new TableGateway('faucet_withdraw', CoreEntityController::$oDbAdapter);
        $rowset = $oWthTbl->select(function(Select $select) {
            $select->columns(array(
                'sum' => new Expression('SUM(amount)')
            ));
        });
        $fBalanceWth = $rowset->toArray()[0]['sum'];

        $rowset = $oWthTbl->select(function(Select $select) {
            $oWh = new Where();
            $oWh->like('date_sent', date('Y-m-d', time()).'%');

            $select->columns(array(
                'sum' => new Expression('SUM(amount)')
            ));
            $select->where($oWh);
        });
        $fBalanceWthTd = $rowset->toArray()[0]['sum'];

        $aAppMetrics = [
            'total_balances' => round($fBalance,0),
            'miner_balances' => round($fMinerBalances,0),
            'admin_balances' => round($fBalanceAdmin,0),
            'small_balances' => round($fBalanceSmall,0),
            'widthdrawable_balances' => round($fBalanceReady,0),
            'withdraw_today' => round($fBalanceWthTd,0),
            'withdraw_total' => round($fBalanceWth,0),
            'withdraw_limit' => round($fTotalLimit,0),
        ];

        $oCheckWh = new Where();
        $oCheckWh->like('stats_key', 'tokenmetrics-daily');
        $oCheckWh->like('date', date('Y-m-d', time()).'%');
        $oStatsCheck = $oStatsTbl->select($oCheckWh);

        if(count($oStatsCheck) == 0) {
            $oStatsTbl->insert([
                'stats_key' => 'tokenmetrics-daily',
                'date' => date('Y-m-d H:i:s', time()),
                'data' => json_encode($aAppMetrics),
            ]);
        } else {
            $oStatsTbl->update([
                'data' => json_encode($aAppMetrics),
                'date' => date('Y-m-d H:i:s', time()),
            ],$oCheckWh);
        }

        echo 'done';

        return false;
    }

    public function withdrawstatsAction()
    {
        $this->layout('layout/json');

        $oStatsTbl = new TableGateway('core_statistic', CoreEntityController::$oDbAdapter);
        $oUsrTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);

        $sTime = time();

        $oWthTbl =  new TableGateway('faucet_withdraw', CoreEntityController::$oDbAdapter);
        $rowset = $oWthTbl->select(function(Select $select) {
            $select->columns(array(
                'sum' => new Expression('SUM(amount)')
            ));
        });
        $fBalanceWth = $rowset->toArray()[0]['sum'];

        $rowset = $oWthTbl->select(function(Select $select) {
            $sTime = time();
            $oWh = new Where();
            $oWh->like('date_sent', date('Y-m-d', $sTime).'%');

            $select->columns(array(
                'sum' => new Expression('SUM(amount)')
            ));
            $select->where($oWh);
        });
        $fBalanceWthTd = $rowset->toArray()[0]['sum'];

        $aAppMetrics = [
            'withdraw_today' => round($fBalanceWthTd,0),
            'withdraw_total' => round($fBalanceWth,0),
        ];

        $oCheckWh = new Where();
        $oCheckWh->like('stats_key', 'withdrawmetrics-daily');
        $oCheckWh->like('date', date('Y-m-d', $sTime).'%');
        $oStatsCheck = $oStatsTbl->select($oCheckWh);

        if(count($oStatsCheck) == 0) {
            $oStatsTbl->insert([
                'stats_key' => 'withdrawmetrics-daily',
                'date' => date('Y-m-d H:i:s',$sTime),
                'data' => json_encode($aAppMetrics),
            ]);
        } else {
            $oStatsTbl->update([
                'data' => json_encode($aAppMetrics),
                'date' => date('Y-m-d H:i:s', $sTime),
            ],$oCheckWh);
        }

        echo 'done';

        return false;
    }

    public function fetchnanosharesAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $hours = 17;
        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/etc/sharesperworker/0x9b79a4ad71e6f1db71adc5b4f0dddbee4c1bcad1/'.$hours);
        $oApiData = json_decode($sApiINfo);

        $oMinerTbl = $this->getCustomTable('faucet_miner');
        $oUsrTbl = $this->getCustomTable('user');
        $minersFound = 0;
        $fTotalHash = 0;

        $oSetTbl = $this->getCustomTable('user_setting');

        $sApiINfoHash = file_get_contents('https://api.nanopool.org/v1/etc/avghashratelimited/0x9b79a4ad71e6f1db71adc5b4f0dddbee4c1bcad1/'.$hours);
        $oApiDataHash = json_decode($sApiINfoHash);

        $fTotalShares = 0;
        $fTotalHash = $oApiDataHash->data;
        $aMinersToPay = [];
        if(isset($oApiData->data)) {
            foreach($oApiData->data as $oW) {
                $bIsFaucetMiner = stripos($oW->worker,'swissfaucetio');
                $bIsHackerAchiev = stripos($oW->worker,'hacker');
                if($bIsHackerAchiev === false) {

                } else {
                    $iUserID = substr($oW->worker,strlen('hacker'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }

                    $this->batchAchievement($iUserID, 48);
                }
                if($bIsFaucetMiner === false) {
                    # ignore
                } else {
                    $iUserID = substr($oW->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }
                    if($oMinerUser) {
                        $minersFound++;
                        $iCurrentShares = $oW->shares;
                        $fTotalShares+= $oW->shares;
                        $oLastEntryWh = new Where();
                        $oLastEntryWh->equalTo('user_idfs', $oMinerUser->getID());
                        $oLastEntryWh->greaterThanOrEqualTo('date', date('Y-m-d H:i:s', strtotime('-50 minutes')));
                        $oLastEntryWh->like('coin', 'etc');

                        $oLastSel = new Select($oMinerTbl->getTable());
                        $oLastSel->where($oLastEntryWh);
                        $oLastSel->order('date DESC');
                        $oLastSel->limit(1);

                        if($iCurrentShares < 0) {
                            $iCurrentShares = 0;
                        }

                        $oLastEntry = $oMinerTbl->selectWith($oLastSel);
                        if(count($oLastEntry) == 0) {
                            $aMinersToPay[$oMinerUser->getID()] = $iCurrentShares;
                        } else {
                            echo 'miner shares already parsed within last 60 minutes - ignoring';
                        }
                    }
                }
            }
        }

        /**
         *  $fCoins = round((float)($iCurrentShares/1000)*2000,2);
        $oMinerTbl->insert([
        'user_idfs' => $oMinerUser->getID(),
        'rating' => $iCurrentShares,
        'shares' => $iCurrentShares,
        'amount_coin' => $fCoins,
        'date' => date('Y-m-d H:i:s', time()),
        'coin' => 'etc',
        'pool' => 'nanopool',
        ]);
        echo 'miner added';
         *
         * if($fCoins > 0) {
        $fCurrentBalance = $oUsrTbl->select(['User_ID' => $oMinerUser->getID()])->current()->token_balance;
        $oUsrTbl->update([
        'token_balance' => $fCurrentBalance+$fCoins,
        ],'User_ID = '.$oMinerUser->getID());
        }
         */

        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/etc/approximated_earnings/'.$fTotalHash);
        $earns = json_decode($sApiINfo);

        $fTotalPay = $earns->data->hour->dollars*.8;

        foreach(array_keys($aMinersToPay) as $iMiner) {
            $iShares = $aMinersToPay[$iMiner];
            $myPerc = (100/($fTotalShares/$iShares)/100);
            $myPayDollar = $fTotalPay*$myPerc;
            $myPayCoin = round($myPayDollar*25000);

            $oMinerTbl->insert([
                'user_idfs' => $iMiner,
                'rating' => $iShares,
                'shares' => $iShares,
                'amount_coin' => $myPayCoin,
                'date' => date('Y-m-d H:i:s', time()),
                'coin' => 'etc',
                'pool' => 'nanopool',
            ]);

            if($myPayCoin > 0) {
                $newBalance = $this->executeTransaction($myPayCoin, false, $iMiner, $iShares, 'etc-nanoshares', ($myPerc*100).'% of all shares on pool. dollar val = '.$myPayDollar);
            }
            echo 'Miner '.$iMiner.' has '.($myPerc*100).'% of shares = '.$myPayCoin.' Coins @ $ '.$myPayDollar. ' - new balance = '.$newBalance;

        }

        # update hashrates for users
        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/etc/avghashrateworkers/0x9b79a4ad71e6f1db71adc5b4f0dddbee4c1bcad1/1');
        $workers = json_decode($sApiINfo);

        if(isset($workers->data)) {
            if(is_array($workers->data)) {
                foreach($workers->data as $worker) {
                    $userId = substr($worker->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($userId);
                    } catch(\RuntimeException $e) {

                    }

                    if($oMinerUser) {
                        $oHrCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'gpuminer-currenthashrate']);
                        if(count($oHrCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currenthashrate',
                                'setting_value' => $worker->hashrate
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => $worker->hashrate
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currenthashrate',
                            ]);
                        }
                        $oHrTyCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'gpuminer-currentpool']);
                        if(count($oHrTyCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currentpool',
                                'setting_value' => 'etc'
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => 'etc'
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currentpool',
                            ]);
                        }
                    }
                }
            }
        }

        echo 'done. Found '.$minersFound.' @ '.$fTotalHash.' MH/s - '.$fTotalShares.' shares = $ '.$earns->data->hour->dollars;

        return false;
        //
    }

    public function fetchcfxnanosharesAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/cfx/sharesperworker/aatv9rfhsh3t7n7z1nat36pfbwfkgf04epu5cv0b54/1');
        $oApiData = json_decode($sApiINfo);

        $oMinerTbl = $this->getCustomTable('faucet_miner');
        $oUsrTbl = $this->getCustomTable('user');
        $minersFound = 0;
        $fTotalHash = 0;

        $oSetTbl = $this->getCustomTable('user_setting');

        $sApiINfoHash = file_get_contents('https://api.nanopool.org/v1/cfx/avghashratelimited/aatv9rfhsh3t7n7z1nat36pfbwfkgf04epu5cv0b54/1');
        $oApiDataHash = json_decode($sApiINfoHash);

        $fTotalShares = 0;
        $fTotalHash = $oApiDataHash->data;
        $aMinersToPay = [];
        if(isset($oApiData->data)) {
            foreach($oApiData->data as $oW) {
                $bIsFaucetMiner = stripos($oW->worker,'swissfaucetio');
                $bIsHackerAchiev = stripos($oW->worker,'hacker');
                if($bIsHackerAchiev === false) {

                } else {
                    $iUserID = substr($oW->worker,strlen('hacker'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }

                    $this->batchAchievement($iUserID, 48);
                }
                if($bIsFaucetMiner === false) {
                    # ignore
                } else {
                    $iUserID = substr($oW->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }
                    if($oMinerUser) {
                        $minersFound++;
                        $iCurrentShares = $oW->shares;
                        $fTotalShares+= $oW->shares;
                        $oLastEntryWh = new Where();
                        $oLastEntryWh->equalTo('user_idfs', $oMinerUser->getID());
                        $oLastEntryWh->greaterThanOrEqualTo('date', date('Y-m-d H:i:s', strtotime('-50 minutes')));
                        $oLastEntryWh->like('coin', 'cfx');

                        $oLastSel = new Select($oMinerTbl->getTable());
                        $oLastSel->where($oLastEntryWh);
                        $oLastSel->order('date DESC');
                        $oLastSel->limit(1);

                        if($iCurrentShares < 0) {
                            $iCurrentShares = 0;
                        }

                        $oLastEntry = $oMinerTbl->selectWith($oLastSel);
                        if(count($oLastEntry) == 0) {
                            $aMinersToPay[$oMinerUser->getID()] = $iCurrentShares;
                        } else {
                            echo 'miner shares already parsed within last 60 minutes - ignoring';
                        }
                    }
                }
            }
        }

        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/cfx/approximated_earnings/'.$fTotalHash);
        $earns = json_decode($sApiINfo);

        $fTotalPay = $earns->data->hour->dollars*.8;

        foreach(array_keys($aMinersToPay) as $iMiner) {
            $iShares = $aMinersToPay[$iMiner];
            $myPerc = (100/($fTotalShares/$iShares)/100);
            $myPayDollar = $fTotalPay*$myPerc;
            $myPayCoin = round($myPayDollar*25000);

            $oMinerTbl->insert([
                'user_idfs' => $iMiner,
                'rating' => $iShares,
                'shares' => $iShares,
                'amount_coin' => $myPayCoin,
                'date' => date('Y-m-d H:i:s', time()),
                'coin' => 'cfx',
                'pool' => 'nanopool',
            ]);

            if($myPayCoin > 0) {
                $newBalance = $this->executeTransaction($myPayCoin, false, $iMiner, $iShares, 'cfx-nanoshares', ($myPerc*100).'% of all shares on pool. dollar val = '.$myPayDollar);
            }
            echo 'Miner '.$iMiner.' has '.($myPerc*100).'% of shares = '.$myPayCoin.' Coins @ $ '.$myPayDollar. ' - new balance = '.$newBalance;

        }

        # update hashrates for users
        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/cfx/avghashrateworkers/aatv9rfhsh3t7n7z1nat36pfbwfkgf04epu5cv0b54/1');
        $workers = json_decode($sApiINfo);

        if(isset($workers->data)) {
            if(is_array($workers->data)) {
                foreach($workers->data as $worker) {
                    $userId = substr($worker->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($userId);
                    } catch(\RuntimeException $e) {

                    }

                    if($oMinerUser) {
                        $oHrCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'gpuminer-currenthashrate']);
                        if(count($oHrCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currenthashrate',
                                'setting_value' => $worker->hashrate
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => $worker->hashrate
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currenthashrate',
                            ]);
                        }
                        $oHrTyCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'gpuminer-currentpool']);
                        if(count($oHrTyCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currentpool',
                                'setting_value' => 'cfx'
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => 'cfx'
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currentpool',
                            ]);
                        }
                    }
                }
            }
        }

        echo 'done. Found '.$minersFound.' @ '.$fTotalHash.' MH/s - '.$fTotalShares.' shares = $ '.$earns->data->hour->dollars;

        return false;
        //
    }

    public function fetchrvnnanosharesAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/rvn/sharesperworker/RQMwgG6sY3aby48Hdo7MdQUHZUTUvACCcT/1');
        $oApiData = json_decode($sApiINfo);

        $oMinerTbl = $this->getCustomTable('faucet_miner');
        $oUsrTbl = $this->getCustomTable('user');
        $minersFound = 0;
        $fTotalHash = 0;

        $oSetTbl = $this->getCustomTable('user_setting');

        $sApiINfoHash = file_get_contents('https://api.nanopool.org/v1/rvn/avghashratelimited/RQMwgG6sY3aby48Hdo7MdQUHZUTUvACCcT/1');
        $oApiDataHash = json_decode($sApiINfoHash);

        $fTotalShares = 0;
        $fTotalHash = $oApiDataHash->data;
        $aMinersToPay = [];
        if(isset($oApiData->data)) {
            foreach($oApiData->data as $oW) {
                $bIsFaucetMiner = stripos($oW->worker,'swissfaucetio');
                if($bIsFaucetMiner === false) {
                    # ignore
                } else {
                    $iUserID = substr($oW->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }
                    if($oMinerUser) {
                        $minersFound++;
                        $iCurrentShares = $oW->shares;
                        $fTotalShares+= $oW->shares;
                        $oLastEntryWh = new Where();
                        $oLastEntryWh->equalTo('user_idfs', $oMinerUser->getID());
                        $oLastEntryWh->greaterThanOrEqualTo('date', date('Y-m-d H:i:s', strtotime('-50 minutes')));
                        $oLastEntryWh->like('coin', 'rvn');

                        $oLastSel = new Select($oMinerTbl->getTable());
                        $oLastSel->where($oLastEntryWh);
                        $oLastSel->order('date DESC');
                        $oLastSel->limit(1);

                        if($iCurrentShares < 0) {
                            $iCurrentShares = 0;
                        }

                        $oLastEntry = $oMinerTbl->selectWith($oLastSel);
                        if(count($oLastEntry) == 0) {
                            $aMinersToPay[$oMinerUser->getID()] = $iCurrentShares;
                        } else {
                            echo 'miner shares already parsed within last 60 minutes - ignoring';
                        }
                    }
                }
            }
        }

        /**
         *  $fCoins = round((float)($iCurrentShares/1000)*2000,2);
        $oMinerTbl->insert([
        'user_idfs' => $oMinerUser->getID(),
        'rating' => $iCurrentShares,
        'shares' => $iCurrentShares,
        'amount_coin' => $fCoins,
        'date' => date('Y-m-d H:i:s', time()),
        'coin' => 'etc',
        'pool' => 'nanopool',
        ]);
        echo 'miner added';
         *
         * if($fCoins > 0) {
        $fCurrentBalance = $oUsrTbl->select(['User_ID' => $oMinerUser->getID()])->current()->token_balance;
        $oUsrTbl->update([
        'token_balance' => $fCurrentBalance+$fCoins,
        ],'User_ID = '.$oMinerUser->getID());
        }
         */

        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/rvn/approximated_earnings/'.$fTotalHash);
        $earns = json_decode($sApiINfo);

        $fTotalPay = $earns->data->hour->dollars*.8;
        if($fTotalPay <= 0) {
            echo "invalid amount ".$fTotalPay." - cancel batch - ".$fTotalHash;
            return false;
        }

        foreach(array_keys($aMinersToPay) as $iMiner) {
            $iShares = $aMinersToPay[$iMiner];
            $myPerc = (100/($fTotalShares/$iShares)/100);
            $myPayDollar = $fTotalPay*$myPerc;
            $myPayCoin = round($myPayDollar*25000);

            $oMinerTbl->insert([
                'user_idfs' => $iMiner,
                'rating' => $iShares,
                'shares' => $iShares,
                'amount_coin' => $myPayCoin,
                'date' => date('Y-m-d H:i:s', time()),
                'coin' => 'rvn',
                'pool' => 'nanopool',
            ]);

            if($myPayCoin > 0) {
                $newBalance = $this->executeTransaction($myPayCoin, false, $iMiner, $iShares, 'rvn-nanoshares', ($myPerc*100).'% of all shares on pool. dollar val = '.$myPayDollar);
            }
            echo 'Miner '.$iMiner.' has '.($myPerc*100).'% of shares = '.$myPayCoin.' Coins @ $ '.$myPayDollar. ' - new balance = '.$newBalance;

        }

        # update hashrates for users
        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/rvn/avghashrateworkers/RQMwgG6sY3aby48Hdo7MdQUHZUTUvACCcT/1');
        $workers = json_decode($sApiINfo);

        if(isset($workers->data)) {
            if(is_array($workers->data)) {
                foreach($workers->data as $worker) {
                    $userId = substr($worker->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($userId);
                    } catch(\RuntimeException $e) {

                    }

                    if($oMinerUser) {
                        $oHrCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'gpuminer-currenthashrate']);
                        if(count($oHrCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currenthashrate',
                                'setting_value' => $worker->hashrate
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => $worker->hashrate
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currenthashrate',
                            ]);
                        }
                        $oHrTyCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'gpuminer-currentpool']);
                        if(count($oHrTyCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currentpool',
                                'setting_value' => 'rvn'
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => 'rvn'
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'gpuminer-currentpool',
                            ]);
                        }
                    }
                }
            }
        }

        echo 'done. Found '.$minersFound.' @ '.$fTotalHash.' MH/s - '.$fTotalShares.' shares = $ '.$earns->data->hour->dollars;

        return false;
        //
    }

    public function generateuserchattagsAction() {
        $this->layout('layout/json');

        $usrTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);
        $noTagUsers = $usrTbl->select();
        foreach($noTagUsers as $usr) {
            $usrBase = $usr->username;
            $hasMail = stripos($usr->username,'@');
            if($hasMail === false) {
            } else {
                $usrBase = explode('@', $usr->username)[0];
            }
            $tag = str_replace([
                ' ','ö','ä','ü','@gmail.com','@yahoo.com','@mail.ru','@outlook.es','@hotmail.com','@ukr.net',
                    '@outlook.com','Outlook.es','.com','@'
                ],[
                    '.','o','a','u','','','','','','','','','',''
                ], substr($usrBase, 0, 100)).'#'.substr($usr->User_ID,strlen($usr->User_ID)-4);

            $usrTbl->update([
                'friend_tag' => $tag,
            ],['User_ID' => $usr->User_ID]);
        }

        echo 'gen tags';

        return false;
    }

    public function fetchxmrnanosharesAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/xmr/sharesperworker/45mYciovPc8GNWBuQaymPyGcNvubron5DeyVRNgMRAExHCumTDZXwnH657atftktRkEF4xD14wcFZTcCaWzo99wg317afGf/1');
        $oApiData = json_decode($sApiINfo);

        $oMinerTbl = $this->getCustomTable('faucet_miner');
        $oUsrTbl = $this->getCustomTable('user');
        $minersFound = 0;
        $fTotalHash = 0;

        $oSetTbl = $this->getCustomTable('user_setting');

        $sApiINfoHash = file_get_contents('https://api.nanopool.org/v1/xmr/avghashratelimited/45mYciovPc8GNWBuQaymPyGcNvubron5DeyVRNgMRAExHCumTDZXwnH657atftktRkEF4xD14wcFZTcCaWzo99wg317afGf/1');
        $oApiDataHash = json_decode($sApiINfoHash);

        $fTotalShares = 0;
        $fTotalHash = $oApiDataHash->data;
        $aMinersToPay = [];
        if(isset($oApiData->data)) {
            foreach($oApiData->data as $oW) {
                $bIsHackerAchiev = stripos($oW->worker,'hacker');
                if($bIsHackerAchiev === false) {

                } else {
                    $iUserID = substr($oW->worker,strlen('hacker'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }

                    $this->batchAchievement($iUserID, 48);
                }
                $bIsFaucetMiner = stripos($oW->worker,'swissfaucetio');
                if($bIsFaucetMiner === false) {
                    # ignore
                } else {
                    $iUserID = substr($oW->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($iUserID);
                    } catch(\RuntimeException $e) {
                        # user not found
                    }
                    if($oMinerUser) {
                        $minersFound++;
                        $iCurrentShares = $oW->shares;
                        $fTotalShares+= $oW->shares;
                        $oLastEntryWh = new Where();
                        $oLastEntryWh->equalTo('user_idfs', $oMinerUser->getID());
                        $oLastEntryWh->greaterThanOrEqualTo('date', date('Y-m-d H:i:s', strtotime('-50 minutes')));
                        $oLastEntryWh->like('coin', 'xmr');

                        $oLastSel = new Select($oMinerTbl->getTable());
                        $oLastSel->where($oLastEntryWh);
                        $oLastSel->order('date DESC');
                        $oLastSel->limit(1);

                        if($iCurrentShares < 0) {
                            $iCurrentShares = 0;
                        }

                        $oLastEntry = $oMinerTbl->selectWith($oLastSel);
                        if(count($oLastEntry) == 0) {
                            $aMinersToPay[$oMinerUser->getID()] = $iCurrentShares;
                        } else {
                            echo 'miner shares already parsed within last 60 minutes - ignoring';
                        }
                    }
                }
            }
        }

        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/xmr/approximated_earnings/'.$fTotalHash);
        $earns = json_decode($sApiINfo);

        $fTotalPay = $earns->data->hour->dollars*.8;

        foreach(array_keys($aMinersToPay) as $iMiner) {
            $iShares = $aMinersToPay[$iMiner];
            $myPerc = (100/($fTotalShares/$iShares)/100);
            $myPayDollar = $fTotalPay*$myPerc;
            $myPayCoin = round($myPayDollar*25000);

            $oMinerTbl->insert([
                'user_idfs' => $iMiner,
                'rating' => $iShares,
                'shares' => $iShares,
                'amount_coin' => $myPayCoin,
                'date' => date('Y-m-d H:i:s', time()),
                'coin' => 'xmr',
                'pool' => 'nanopool',
            ]);

            if($myPayCoin > 0) {
                $newBalance = $this->executeTransaction($myPayCoin, false, $iMiner, $iShares, 'xmr-nanoshares', ($myPerc*100).'% of all shares on pool. dollar val = '.$myPayDollar);
            }
            echo 'Miner '.$iMiner.' has '.($myPerc*100).'% of shares = '.$myPayCoin.' Coins @ $ '.$myPayDollar. ' - new balance = '.$newBalance;

        }

        # update hashrates for users
        $sApiINfo = file_get_contents('https://api.nanopool.org/v1/xmr/avghashrateworkers/45mYciovPc8GNWBuQaymPyGcNvubron5DeyVRNgMRAExHCumTDZXwnH657atftktRkEF4xD14wcFZTcCaWzo99wg317afGf/1');
        $workers = json_decode($sApiINfo);

        if(isset($workers->data)) {
            if(is_array($workers->data)) {
                foreach($workers->data as $worker) {
                    $userId = substr($worker->worker,strlen('swissfaucetio'));
                    $oMinerUser = false;
                    try {
                        $oMinerUser = $this->oTableGateway->getSingle($userId);
                    } catch(\RuntimeException $e) {

                    }

                    if($oMinerUser) {
                        $oHrCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'cpuminer-currenthashrate']);
                        if(count($oHrCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'cpuminer-currenthashrate',
                                'setting_value' => $worker->hashrate
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => $worker->hashrate
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'cpuminer-currenthashrate',
                            ]);
                        }
                        $oHrTyCheck = $oSetTbl->select(['user_idfs' => $userId,'setting_name' => 'cpuminer-currentpool']);
                        if(count($oHrTyCheck) == 0) {
                            $oSetTbl->insert([
                                'user_idfs' => $userId,
                                'setting_name' => 'cpuminer-currentpool',
                                'setting_value' => 'xmr'
                            ]);
                        } else {
                            $oSetTbl->update([
                                'setting_value' => 'xmr'
                            ], [
                                'user_idfs' => $userId,
                                'setting_name' => 'cpuminer-currentpool',
                            ]);
                        }
                    }
                }
            }
        }

        # set hashrate to 0 for all miners gone
        $aMinersActive = $oSetTbl->select(['setting_name' => 'cpuminer-currenthashrate']);
        foreach($aMinersActive as $oActive) {
            if($oActive->user_idfs == 0) {
                continue;
            }
            if(!array_key_exists($oActive->user_idfs,$aMinersToPay)) {
                $oSetTbl->update(['setting_value' => 0],
                    [
                        'user_idfs' => $oActive->user_idfs,
                        'setting_name' => 'cpuminer-currenthashrate',
                    ]
                );
                $oSetTbl->delete(
                    [
                        'user_idfs' => $oActive->user_idfs,
                        'setting_name' => 'cpuminer-currentpool',
                    ]
                );
            }
        }


        echo 'done. Found '.$minersFound.' @ '.$fTotalHash.' MH/s - '.$fTotalShares.' shares = $ '.$earns->data->hour->dollars;

        return false;
        //
    }

    public function startlotteryroundAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $oLotteryTbl = $this->getCustomTable('faucet_lottery_round');
        $oLotteryTbl->insert([
            'server_hash' => '',
            'date_started' => date('Y-m-d H:i:s', time()),
            'date_end' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);

        echo 'next round started';

        return false;
    }

    public function transactionstatsAction() {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $oTransTbl = $this->getCustomtable('faucet_transaction');

        $oTodayWh = new Where();
        $oTodayWh->like('date', date('Y-m-d', time()).'%');
        $oTodayTrans = $oTransTbl->select($oTodayWh);
        $iTotalOut = 0;
        $iTotalIn = 0;
        $iTotalTrans = 0;
        $aAmountByType = [];
        $aAmountByTypeOut = [];
        foreach($oTodayTrans as $oT) {
            $iTotalTrans++;
            if($oT->is_output == 1) {
                $iTotalOut+=$oT->amount;
                if(!array_key_exists($oT->ref_type,$aAmountByTypeOut)) {
                    $aAmountByTypeOut[$oT->ref_type] = 0;
                }
                $aAmountByTypeOut[$oT->ref_type]+=$oT->amount;
            } else {
                $iTotalIn+=$oT->amount;
                if(!array_key_exists($oT->ref_type,$aAmountByType)) {
                    $aAmountByType[$oT->ref_type] = 0;
                }
                $aAmountByType[$oT->ref_type]+=$oT->amount;
            }
        }

        $sTime = time();

        $oStatsTbl = new TableGateway('core_statistic', CoreEntityController::$oDbAdapter);

        $aAppMetrics = [
            'outputs' => $aAmountByTypeOut,
            'inputs' => $aAmountByType,
            'total_count' => $iTotalTrans,
            'total_in' => $iTotalIn,
            'total_out' => $iTotalOut,
        ];

        $oCheckWh = new Where();
        $oCheckWh->like('stats_key', 'transmetrics-daily');
        $oCheckWh->like('date', date('Y-m-d', $sTime).'%');
        $oStatsCheck = $oStatsTbl->select($oCheckWh);

        if(count($oStatsCheck) == 0) {
            $oStatsTbl->insert([
                'stats_key' => 'transmetrics-daily',
                'date' => date('Y-m-d H:i:s',$sTime),
                'data' => json_encode($aAppMetrics),
            ]);
        } else {
            $oStatsTbl->update([
                'data' => json_encode($aAppMetrics),
                'date' => date('Y-m-d H:i:s', $sTime),
            ],$oCheckWh);
        }

        echo 'done';

        return false;
    }

    public function gamestatsAction()
    {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            if($_REQUEST['authkey'] != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $oTransTbl = $this->getCustomtable('faucet_game_match');

        $oTodayWh = new Where();
        $oTodayWh->like('date_matched', date('Y-m-d', time()).'%');
        $oTodayTrans = $oTransTbl->select($oTodayWh);
        $iTotalOut = 0;
        $iTotalIn = 0;
        $iTotalTrans = 0;
        $aAmountByType = [];
        $iHostWin = 0;
        $iHostLose = 0;
        $iClientWin = 0;
        $iClientLose = 0;
        $iEven = 0;
        foreach($oTodayTrans as $oT) {
            $iTotalTrans++;
            $iTotalIn+=$oT->amount_bet;
            if(!array_key_exists($oT->client_user_idfs,$aAmountByType)) {
                $aAmountByType[$oT->client_user_idfs] = true;
            }
            if(!array_key_exists($oT->host_user_idfs,$aAmountByType)) {
                $aAmountByType[$oT->host_user_idfs] = true;
            }
            if($oT->winner_idfs == 0) {
                $iEven++;
            } elseif($oT->winner_idfs == $oT->host_user_idfs) {
                $iHostWin++;
                $iClientLose++;
            } else {
                $iClientWin++;
                $iHostLose++;
            }
        }

        $aMetrics = [
            'iTotalTrans' => $iTotalTrans,
            'iTotalIn' => $iTotalIn,
            'iPlayers' => count($aAmountByType),
            'iEven' => $iEven,
            'iClientLose' => $iClientLose,
            'iClientWin' => $iClientWin,
            'iHostWin' => $iHostWin,
            'iHostLose' => $iHostLose,
        ];

        $sTime = time();

        $oStatsTbl = new TableGateway('core_statistic', CoreEntityController::$oDbAdapter);

        $oCheckWh = new Where();
        $oCheckWh->like('stats_key', 'gamemetrics-daily');
        $oCheckWh->like('date', date('Y-m-d', $sTime).'%');
        $oStatsCheck = $oStatsTbl->select($oCheckWh);

        if(count($oStatsCheck) == 0) {
            $oStatsTbl->insert([
                'stats_key' => 'gamemetrics-daily',
                'date' => date('Y-m-d H:i:s',$sTime),
                'data' => json_encode($aMetrics),
            ]);
        } else {
            $oStatsTbl->update([
                'data' => json_encode($aMetrics),
                'date' => date('Y-m-d H:i:s', $sTime),
            ],$oCheckWh);
        }

        echo 'done';

        return false;
    }

    public function guildweeklysAction()
    {
        $bCheck = true;
        if (!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if ($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if (!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $claimTbl = new TableGateway('faucet_claim', CoreEntityController::$oDbAdapter);
        $minerTbl = new TableGateway('faucet_miner', CoreEntityController::$oDbAdapter);
        $shortDoneTbl = new TableGateway('shortlink_link_user', CoreEntityController::$oDbAdapter);
        $guildTbl = new TableGateway('faucet_guild', CoreEntityController::$oDbAdapter);
        $statsTbl = new TableGateway('faucet_guild_statistic', CoreEntityController::$oDbAdapter);
        $guildUserTbl = new TableGateway('faucet_guild_user', CoreEntityController::$oDbAdapter);
        $weeklyClaimTbl = new TableGateway('faucet_guild_weekly_claim', CoreEntityController::$oDbAdapter);
        $guilds = $guildTbl->select();

        $weekDay = date('w', time());
        $weeklyStart = date('Y-m-d H:i:s', strtotime("last wednesday"));
        if($weekDay == 3) {
            $weeklyStart = date('Y-m-d H:i:s', time());
        }

        foreach($guilds as $guild) {
            $guildInfo = ['faucet_claims' => 0,'shortlinks' => 0,'gpushares' => 0];
            $memberWh = new Where();
            $memberWh->equalTo('guild_idfs', $guild->Guild_ID);
            $memberWh->notLike('date_joined', '0000-00-00 00:00:00');
            $members = $guildUserTbl->select($memberWh);

            if(count($members) > 0) {
                foreach($members as $member) {
                    # count faucet claims
                    $claimWh = new Where();
                    $claimWh->equalTo('user_idfs', $member->user_idfs);
                    $claimWh->greaterThanOrEqualTo('date', $weeklyStart);
                    $claimWh->like('source', 'website');
                    $guildInfo['faucet_claims']+= $claimTbl->select($claimWh)->count();

                    # count shortlinks done
                    $shDoneWh = new Where();
                    $shDoneWh->equalTo('user_idfs', $member->user_idfs);
                    $shDoneWh->greaterThanOrEqualTo('date_completed', $weeklyStart);
                    $guildInfo['shortlinks']+= $shortDoneTbl->select($shDoneWh)->count();

                    # count miner shares
                    $gpuDoneWh = new Where();
                    $gpuDoneWh->equalTo('user_idfs', $member->user_idfs);
                    $gpuDoneWh->like('pool', 'nanopool');
                    $gpuDoneWh->greaterThanOrEqualTo('date', $weeklyStart);
                    $gpuShares = $minerTbl->select($gpuDoneWh);
                    if(count($gpuShares) > 0) {
                        foreach($gpuShares as $share) {
                            $guildInfo['gpushares']+= $share->shares;
                        }
                    }
                }
            }

            $statCheckWh = new Where();
            $statCheckWh->equalTo('guild_idfs', $guild->Guild_ID);
            $statCheckWh->like('stat_key', 'weekly-progress');
            $statCheckWh->like('date', date('Y-m-d', strtotime($weeklyStart)));

            $statCheck = $statsTbl->select($statCheckWh);
            if(count($statCheck) == 0) {
                $statsTbl->insert([
                    'guild_idfs' => $guild->Guild_ID,
                    'stat_key' => 'weekly-progress',
                    'date' => date('Y-m-d', strtotime($weeklyStart)),
                    'data' => json_encode($guildInfo)
                ]);
            } else {
                $statsTbl->update([
                    'data' => json_encode($guildInfo)
                ], [
                    'guild_idfs' => $guild->Guild_ID,
                    'stat_key' => 'weekly-progress',
                    'date' => date('Y-m-d', strtotime($weeklyStart)),
                ]);
            }

            # check claims
            if($guildInfo['faucet_claims'] >= 1000) {
                $claimWh = new Where();
                $claimWh->equalTo('guild_idfs', $guild->Guild_ID);
                $claimWh->equalTo('week', date('W', strtotime($weeklyStart)));
                $claimWh->equalTo('weekly_idfs', 2);
                $weeklyClaimedFaucet = $weeklyClaimTbl->select($claimWh);
                if(count($weeklyClaimedFaucet) == 0) {
                    $transID = $this->executeGuildTransaction(1000, false, (int)$guild->Guild_ID, 2, 'weekly-task', 'Weekly Task 1000 Faucet Claim complete', 1);
                    $weeklyClaimTbl->insert([
                        'guild_idfs' => $guild->Guild_ID,
                        'week' => date('W', strtotime($weeklyStart)),
                        'weekly_idfs' => 2,
                        'reward' => 1000,
                        'transaction_id' => $transID,
                        'date_claimed' => date('Y-m-d H:i:s', time()),
                    ]);
                }
            }

            if($guildInfo['shortlinks'] >= 1000) {
                $claimWh = new Where();
                $claimWh->equalTo('guild_idfs', $guild->Guild_ID);
                $claimWh->equalTo('week', date('W', strtotime($weeklyStart)));
                $claimWh->equalTo('weekly_idfs', 3);
                $weeklyClaimed = $weeklyClaimTbl->select($claimWh);
                if(count($weeklyClaimed) == 0) {
                    $transID = $this->executeGuildTransaction(1000, false, (int)$guild->Guild_ID, 3, 'weekly-task', 'Weekly Task 1000 Shortlinks complete', 1);
                    $weeklyClaimTbl->insert([
                        'guild_idfs' => $guild->Guild_ID,
                        'week' => date('W', strtotime($weeklyStart)),
                        'weekly_idfs' => 3,
                        'reward' => 1000,
                        'transaction_id' => $transID,
                        'date_claimed' => date('Y-m-d H:i:s', time()),
                    ]);
                }
            }
        }

        echo 'done';

        return false;
    }

    /**
     * Calculate Shortlink Difficulty
     *
     * @return false
     */
    public function shdifficultyAction() {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $oShTbl = new TableGateway('shortlink', CoreEntityController::$oDbAdapter);
        $oShUsrTbl = new TableGateway('shortlink_link_user', CoreEntityController::$oDbAdapter);

        $shorts = $oShTbl->select(['active' => 1]);
        foreach($shorts as $sh) {
            $complete = 0;
            $started = 0;

            $doneSel = new Select($oShUsrTbl->getTable());
            $doneSel->where(['shortlink_idfs' => $sh->Shortlink_ID]);
            $doneSel->order('date_started DESC');
            $doneSel->limit(10000);
            $shDone = $oShUsrTbl->selectWith($doneSel);
            if(count($shDone) > 0) {
                foreach($shDone as $shD) {
                    $started++;
                    if($shD->date_completed != '0000-00-00 00:00:00') {
                        $complete++;
                    }
                }
            }

            $percent = round((100/(($started)/$complete)));

            $difficulty = 'easy';
            if($percent <= 90 & $percent >= 80) {
                $difficulty = 'medium';
            }
            if($percent < 80 & $percent >= 70) {
                $difficulty = 'hard';
            }
            if($percent < 70) {
                $difficulty = 'ultra';
            }

            $oShTbl->update([
                'difficulty' => $difficulty
            ],[
                'Shortlink_ID' => $sh->Shortlink_ID
            ]);
        }

        echo 'done';

        return false;
    }

    public function refbonusAction() {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $oUsrTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);
        $statsTbl = new TableGateway('user_statistic', CoreEntityController::$oDbAdapter);
        $wthTable = new TableGateway('faucet_withdraw', CoreEntityController::$oDbAdapter);

        $usersToCheck = $oUsrTbl->select();
        foreach($usersToCheck as $user) {
            $userRefs = $oUsrTbl->select(['ref_user_idfs' => $user->User_ID]);
            $refWithdrawn = 0;
            if(count($userRefs) > 0) {
                foreach($userRefs as $ref) {
                    $refWth = $wthTable->select(['user_idfs' => $ref->User_ID,'state' => 'done']);
                    if(count($refWth) > 0) {
                        foreach($refWth as $wth) {
                            $refWithdrawn+=$wth->amount;
                        }
                    }
                }
            }
            $checkWh = new Where();
            $checkWh->like('stat_key', 'user-ref-bonus');
            $checkWh->equalTo('user_idfs', $user->User_ID);
            $checkWh->like('date', date('Y-m-d', time()).'%');

            $statCheck = $statsTbl->select($checkWh);
            if(count($statCheck) == 0) {
                 $statsTbl->insert([
                    'data' => json_encode(['withdrawn' => $refWithdrawn,'bonus' => $refWithdrawn*.1]),
                    'stat_key' => 'user-ref-bonus',
                    'date' => date('Y-m-d H:i:s', time()),
                    'user_idfs' => $user->User_ID
                ]);
            } else {
                $statsTbl->update([
                    'data' => json_encode(['withdrawn' => $refWithdrawn,'bonus' => $refWithdrawn*.1]),
                    'date' => date('Y-m-d H:i:s', time())
                ], $checkWh);
            }
        }

        echo 'done';

        return false;
    }

    public function checkminerachievsAction()
    {
        $bCheck = true;
        if (!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if ($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if (!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $minerTbl = new TableGateway('faucet_miner', CoreEntityController::$oDbAdapter);
        $inventoryTbl = new TableGateway('faucet_item_user', CoreEntityController::$oDbAdapter);

        $minerInfoByUser = [];
        $minersInfo = $minerTbl->select();
        foreach($minersInfo as $mi) {
            if(!array_key_exists($mi->user_idfs,$minerInfoByUser)) {
                $minerInfoByUser[$mi->user_idfs] = [
                    'gpu' => ['h' => 0,'s' => 0,'d' => [],'hn' => 0,'w' => 0,'c' => 0,'m' => 0],
                    'gpuetc' => ['s' => 0,'c' => 0,'m' => 0],
                    'cpu' => ['h' => 0,'s' => 0,'d' => [],'hn' => 0,'w' => 0,'m' => 0,'c' => 0],
                    'web' => ['h' => 0,'s' => 0,'d' => [],'hn' => 0,'w' => 0]
                ];
            }
            $miningHour = (int)date('H', strtotime($mi->date));
            $miningDay = (int)date('w', strtotime($mi->date));

            switch($mi->coin) {
                case 'etc':
                    $minerInfoByUser[$mi->user_idfs]['gpuetc']['s']+=$mi->shares;
                    $minerInfoByUser[$mi->user_idfs]['gpu']['h']++;
                    $minerInfoByUser[$mi->user_idfs]['gpuetc']['c']+=$mi->amount_coin;
                    $minerInfoByUser[$mi->user_idfs]['gpu']['d'][date('Y-m-d', strtotime($mi->date))] = 1;
                    if($miningHour >= 20 || $miningHour <= 6) {
                        $minerInfoByUser[$mi->user_idfs]['gpu']['hn']++;
                    }
                    if($miningDay == 0 || $miningDay == 6) {
                        $minerInfoByUser[$mi->user_idfs]['gpu']['w']++;
                    }
                    if(strtotime($mi->date) >= strtotime('first day of this month')) {
                        $minerInfoByUser[$mi->user_idfs]['gpuetc']['m']+=$mi->amount_coin;
                    }
                    break;
                case 'rvn':
                    $minerInfoByUser[$mi->user_idfs]['gpu']['s']+=$mi->shares;
                    $minerInfoByUser[$mi->user_idfs]['gpu']['c']+=$mi->amount_coin;
                    $minerInfoByUser[$mi->user_idfs]['gpu']['h']++;
                    $minerInfoByUser[$mi->user_idfs]['gpu']['d'][date('Y-m-d', strtotime($mi->date))] = 1;
                    if($miningHour >= 20 || $miningHour <= 6) {
                        $minerInfoByUser[$mi->user_idfs]['gpu']['hn']++;
                    }
                    if($miningDay == 0 || $miningDay == 6) {
                        $minerInfoByUser[$mi->user_idfs]['gpu']['w']++;
                    }
                    if(strtotime($mi->date) >= strtotime('first day of this month')) {
                        $minerInfoByUser[$mi->user_idfs]['gpu']['m']+=$mi->amount_coin;
                    }
                    break;
                case 'wmp':
                    $minerInfoByUser[$mi->user_idfs]['web']['s']+=$mi->shares;
                    $minerInfoByUser[$mi->user_idfs]['web']['h']++;
                    break;
                case 'xmr':
                    $minerInfoByUser[$mi->user_idfs]['cpu']['s']+=$mi->shares;
                    $minerInfoByUser[$mi->user_idfs]['cpu']['c']+=$mi->amount_coin;
                    $minerInfoByUser[$mi->user_idfs]['cpu']['h']++;
                    $minerInfoByUser[$mi->user_idfs]['cpu']['d'][date('Y-m-d', strtotime($mi->date))] = 1;
                    if($miningHour >= 20 || $miningHour <= 6) {
                        $minerInfoByUser[$mi->user_idfs]['cpu']['hn']++;
                    }
                    if($miningDay == 0 || $miningDay == 6) {
                        $minerInfoByUser[$mi->user_idfs]['cpu']['w']++;
                    }
                    if(strtotime($mi->date) >= strtotime('first day of this month')) {
                        $minerInfoByUser[$mi->user_idfs]['cpu']['m']+=$mi->amount_coin;
                    }
                    break;
                default:
                    break;
            }
        }

        echo "\n".'Found Data for '.count($minerInfoByUser).' Miners';

        foreach(array_keys($minerInfoByUser) as $minerId) {
            $minerInfo = $minerInfoByUser[$minerId];
            /**
             * 1 Hour Mining Achievement
             */
            if($minerInfo['gpu']['h'] >= 2) {
                $this->batchAchievement($minerId, 37);
            } elseif($minerInfo['cpu']['h'] >= 2) {
                $this->batchAchievement($minerId, 37);
            } elseif($minerInfo['web']['h'] >= 2) {
                $this->batchAchievement($minerId, 37);
            }

            /**
             * 24 hours mining
             */
            if($minerInfo['gpu']['h'] >= 24) {
                $this->batchAchievement($minerId, 38);
            } elseif($minerInfo['cpu']['h'] >= 24) {
                $this->batchAchievement($minerId, 38);
            } elseif($minerInfo['web']['h'] >= 24) {
                $this->batchAchievement($minerId, 38);
            }

            /**
             * 72 hours mining
             */
            if($minerInfo['gpu']['h'] >= 72) {
                $this->batchAchievement($minerId, 39);
            } elseif($minerInfo['cpu']['h'] >= 72) {
                $this->batchAchievement($minerId, 39);
            } elseif($minerInfo['web']['h'] >= 72) {
                $this->batchAchievement($minerId, 39);
            }

            /**
             * 7 days mining
             */
            if($minerInfo['gpu']['h'] >= 168) {
                $this->batchAchievement($minerId, 40);
            } elseif($minerInfo['cpu']['h'] >= 168) {
                $this->batchAchievement($minerId, 40);
            } elseif($minerInfo['web']['h'] >= 168) {
                $this->batchAchievement($minerId, 40);
            }

            /**
             * GPU Shares Achievements
             */
            if($minerInfo['gpu']['s'] >= 1000000) {
                $this->batchAchievement($minerId, 43);
            }
            if($minerInfo['gpu']['s'] >= 100000) {
                $this->batchAchievement($minerId, 42);
            }
            if($minerInfo['gpu']['s'] >= 10000) {
                $this->batchAchievement($minerId, 41);
            }

            /**
             * Mining 4 Hours at 5 days at Night Achievement
             */
            if($minerInfo['gpu']['hn'] >= (4*5)) {
                $this->batchAchievement($minerId, 45);
            } elseif($minerInfo['cpu']['hn'] >= (4*5)) {
                $this->batchAchievement($minerId, 45);
            }

            /**
             * Mining 4 Hours at Night Achievement
             */
            if($minerInfo['gpu']['hn'] >= 4) {
                $this->batchAchievement($minerId, 44);
            } elseif($minerInfo['cpu']['hn'] >= 4) {
                $this->batchAchievement($minerId, 44);
            }

            /**
             * Mining on the Weekend Achievement
             */
            if($minerInfo['gpu']['w'] >= 2) {
                $this->batchAchievement($minerId, 46);
            } elseif($minerInfo['cpu']['w'] >= 2) {
                $this->batchAchievement($minerId, 46);
            }

            /**
             * Mining for over a month Achievement
             */
            if(count($minerInfo['cpu']['d']) >= 30) {
                $this->batchAchievement($minerId, 47);
            } elseif(count($minerInfo['gpu']['d']) >= 30) {
                $this->batchAchievement($minerId, 47);
            }

            /**
             * Update Counters
             */
            if(!empty($minerInfo['gpu']['m']) && $minerInfo['gpu']['m'] != null) {
                $this->updateUserSetting($minerId, 'gpuminer-rvn-month', $minerInfo['gpu']['m']);
            }
            if(!empty($minerInfo['gpuetc']['m']) && $minerInfo['gpuetc']['m'] != null) {
                $this->updateUserSetting($minerId, 'gpuminer-etc-month', $minerInfo['gpuetc']['m']);
            }
            if(!empty($minerInfo['cpu']['m']) && $minerInfo['cpu']['m'] != null) {
                $this->updateUserSetting($minerId, 'cpuminer-month', $minerInfo['cpu']['m']);
            }
            if(!empty($minerInfo['cpu']['c']) && $minerInfo['cpu']['c'] != null) {
                $this->updateUserSetting($minerId, 'cpuminer-totalcoins', $minerInfo['cpu']['c']);
            }

            /**
             * Update Achievement Stats
             */
            if(!empty($minerInfo['gpu']['s']) && $minerInfo['gpu']['s'] != null) {
                $this->updateUserSetting($minerId, 'gpuminer-totalshares', $minerInfo['gpu']['s']);
            }
            if(!empty($minerInfo['gpu']['c']) && $minerInfo['gpu']['c'] != null) {
                $minerGems = round($minerInfo['gpu']['c']/50000);
                $gemsFound = $inventoryTbl->select(['user_idfs' => $minerId,'item_idfs' => 20])->count();
                if($minerGems > $gemsFound) {
                    $gemsToAdd = $minerGems-$gemsFound;
                    for($i = 0;$i < $gemsToAdd;$i++) {
                        $inventoryTbl->insert([
                            'item_idfs' => 20,
                            'user_idfs' => $minerId,
                            'date_created' => date('Y-m-d H:i:s', time()),
                            'date_received' => date('Y-m-d H:i:s', time()),
                            'comment' => 'Find while Mining RVN',
                            'hash' => password_hash('20'.$minerId.'Find while Mining RVN', PASSWORD_DEFAULT),
                            'created_by' => 1,
                            'received_from' => 1,
                            'used' => 0,
                        ]);
                    }
                }
                echo "\n".'Miner '.$minerId.' has '.$gemsFound.' and should have '.$minerGems.' with '.$minerInfo['gpu']['c'].' Coins';
                $this->updateUserSetting($minerId, 'gpuminer-totalcoins', $minerInfo['gpu']['c']);
            }
            if(!empty($minerInfo['gpuetc']['c']) && $minerInfo['gpuetc']['c'] != null) {
                $minerGems = round($minerInfo['gpuetc']['c']/50000);
                $gemsFound = $inventoryTbl->select(['user_idfs' => $minerId,'item_idfs' => 19])->count();
                if($minerGems > $gemsFound) {
                    $gemsToAdd = $minerGems-$gemsFound;
                    for($i = 0;$i < $gemsToAdd;$i++) {
                        $inventoryTbl->insert([
                            'item_idfs' => 19,
                            'user_idfs' => $minerId,
                            'date_created' => date('Y-m-d H:i:s', time()),
                            'date_received' => date('Y-m-d H:i:s', time()),
                            'comment' => 'Find while Mining ETC',
                            'hash' => password_hash('20'.$minerId.'Find while Mining ETC', PASSWORD_DEFAULT),
                            'created_by' => 1,
                            'received_from' => 1,
                            'used' => 0,
                        ]);
                    }
                }
                echo "\n".'Miner '.$minerId.' has '.$gemsFound.' and should have '.$minerGems.' with '.$minerInfo['gpuetc']['c'].' Coins';
                $this->updateUserSetting($minerId, 'gpuminer-etc-totalcoins', $minerInfo['gpuetc']['c']);
            }
            if(!empty($minerInfo['gpuetc']['s']) && $minerInfo['gpuetc']['s'] != null) {
                $this->updateUserSetting($minerId, 'gpuminer-etc-totalshares', $minerInfo['gpuetc']['s']);
            }
            if(!empty($minerInfo['gpu']['hn']) && $minerInfo['gpu']['hn'] != null) {
                $this->updateUserSetting($minerId, 'gpuminer-nighthours', $minerInfo['gpu']['hn']);
            }
            if(!empty($minerInfo['gpu']['d']) && $minerInfo['gpu']['d'] != null) {
                $this->updateUserSetting($minerId, 'gpuminer-totaldays', count($minerInfo['gpu']['d']));
            }
            if(!empty($minerInfo['gpu']['h']) && $minerInfo['gpu']['h'] != null) {
                $this->updateUserSetting($minerId, 'gpuminer-totalhours', $minerInfo['gpu']['h']);
            }
        }

        echo 'done';

        return false;
    }

    public function checkfaucetachievsAction()
    {
        $bCheck = true;
        if (!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if ($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if (!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $claimTbl = new TableGateway('faucet_claim', CoreEntityController::$oDbAdapter);

        $claimsByUser = [];

        $claimSel = new Select($claimTbl->getTable());
        $claimSel->order('date ASC');
        $claimSel->where(['source' => 'website']);
        $claimsDone = $claimTbl->selectWith($claimSel);
        foreach($claimsDone as $claim) {
            $date = date('Y-m-d', strtotime($claim->date));
            if(!array_key_exists($claim->user_idfs, $claimsByUser)) {
                $claimsByUser[$claim->user_idfs] = ['t' => 0,'d' => [],'w' => $date,'wc' => 0,'c' => 0,'c7' => 0];
            }
            if(!array_key_exists($date,$claimsByUser[$claim->user_idfs]['d'])) {
                $claimsByUser[$claim->user_idfs]['d'][$date] = 0;
            }
            if(strtotime($date) >= strtotime('last wednesday')) {
                $claimsByUser[$claim->user_idfs]['c7']++;
            }
            $claimsByUser[$claim->user_idfs]['c']++;
            $claimsByUser[$claim->user_idfs]['d'][$date]++;
            if($date != $claimsByUser[$claim->user_idfs]['w']) {
                //echo "\n - Compare dates ".$date.' / '.$claimsByUser[$claim->user_idfs]['w'];
                if((strtotime($date)-86400) <= strtotime($claimsByUser[$claim->user_idfs]['w'])) {
                    $claimsByUser[$claim->user_idfs]['wc']++;
                    $claimsByUser[$claim->user_idfs]['w'] = $date;
                } else {
                    $claimsByUser[$claim->user_idfs]['wc'] = 0;
                    $claimsByUser[$claim->user_idfs]['w'] = $date;
                }
            }
        }

        echo "Parsing Claims of ".count($claimsByUser)." Users";

        foreach(array_keys($claimsByUser) as $userId) {
            $dateBiggest = 0;
            foreach(array_keys($claimsByUser[$userId]['d']) as $date) {
                if($claimsByUser[$userId]['d'][$date] > $dateBiggest) {
                    $dateBiggest = $claimsByUser[$userId]['d'][$date];
                }
                if($claimsByUser[$userId]['d'][$date] >= 14) {
                    $this->batchAchievement($userId, 58);
                    echo "\n".'User '.$userId.' has claimed achievement all day long ( '.$claimsByUser[$userId]['d'][$date] .')';
                }
            }
            if($claimsByUser[$userId]['wc'] >= 7) {
                $this->batchAchievement($userId, 31);
                echo "\n".'User '.$userId.' has claimed achievement a daily routine ( '.$claimsByUser[$userId]['wc'] .')';
            }
            $this->updateUserSetting($userId, 'faucet-claimdays', $claimsByUser[$userId]['wc']);
            $this->updateUserSetting($userId, 'faucet-claimtimes', $dateBiggest);
            $this->updateUserSetting($userId, 'faucet-claimtotal', $claimsByUser[$userId]['c']);
            $this->updateUserSetting($userId, 'faucet-claim7d', $claimsByUser[$userId]['c7']);
        }

        echo "\n".'done';

        return false;
    }

    public function checkwithdrawachievsAction()
    {
        $bCheck = true;
        if (!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if ($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if (!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $claimTbl = new TableGateway('faucet_withdraw', CoreEntityController::$oDbAdapter);

        $withdrawsByUser = [];

        $claimSel = new Select($claimTbl->getTable());
        $claimSel->order('date_sent ASC');
        $claimSel->where(['state' => 'done']);
        $claimsDone = $claimTbl->selectWith($claimSel);
        foreach($claimsDone as $claim) {
            if(!array_key_exists($claim->user_idfs, $withdrawsByUser)) {
                $withdrawsByUser[$claim->user_idfs] = ['c' => [],'w' => 0];
            }
            $withdrawsByUser[$claim->user_idfs]['w']++;
            if(!array_key_exists($claim->currency,$withdrawsByUser[$claim->user_idfs]['c'])) {
                $withdrawsByUser[$claim->user_idfs]['c'][$claim->currency] = 0;
            }
            $withdrawsByUser[$claim->user_idfs]['c'][$claim->currency]++;
        }

        echo "Parsing Withdrawals of ".count($withdrawsByUser)." Users";

        foreach(array_keys($withdrawsByUser) as $userId) {
            if($withdrawsByUser[$userId]['w'] >= 1) {
                $this->batchAchievement($userId, 33);
            }
            if($withdrawsByUser[$userId]['w'] >= 10) {
                $this->batchAchievement($userId, 34);
            }
            if(count($withdrawsByUser[$userId]['c']) >= 3) {
                $this->batchAchievement($userId, 35);
            }
            if(count($withdrawsByUser[$userId]['c']) >= 6) {
                $this->batchAchievement($userId, 36);
            }
            $this->updateUserSetting($userId, 'withdraw-coins', json_encode($withdrawsByUser[$userId]['c']));
            $this->updateUserSetting($userId, 'withdraw-total', $withdrawsByUser[$userId]['w']);
        }

        echo "\n".'done';

        return false;
    }

    public function checktransactionachievsAction()
    {
        $bCheck = true;
        if (!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if ($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if (!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $claimTbl = new TableGateway('faucet_transaction', CoreEntityController::$oDbAdapter);

        $withdrawsByUser = [];

        $claimSel = new Select($claimTbl->getTable());
        $claimSel->order('date ASC');
        $claimSel->where(['is_output' => 0]);
        $claimsDone = $claimTbl->selectWith($claimSel);
        foreach($claimsDone as $claim) {
            if(!array_key_exists($claim->user_idfs, $withdrawsByUser)) {
                $withdrawsByUser[$claim->user_idfs] = ['c' => 0];
            }
            $withdrawsByUser[$claim->user_idfs]['c']+=$claim->amount;
        }

        echo "Parsing Transactions of ".count($withdrawsByUser)." Users";

        foreach(array_keys($withdrawsByUser) as $userId) {
            if($withdrawsByUser[$userId]['c'] >= 1000) {
                $this->batchAchievement($userId, 6);
            }
            if($withdrawsByUser[$userId]['c'] >= 5000) {
                $this->batchAchievement($userId, 7);
            }
            if($withdrawsByUser[$userId]['c'] >= 10000) {
                $this->batchAchievement($userId, 8);
            }
            if($withdrawsByUser[$userId]['c'] >= 25000) {
                $this->batchAchievement($userId, 9);
            }
            if($withdrawsByUser[$userId]['c'] >= 50000) {
                $this->batchAchievement($userId, 10);
            }
            if($withdrawsByUser[$userId]['c'] >= 100000) {
                $this->batchAchievement($userId, 11);
            }
            if($withdrawsByUser[$userId]['c'] >= 250000) {
                $this->batchAchievement($userId, 12);
            }
            if($withdrawsByUser[$userId]['c'] >= 500000) {
                $this->batchAchievement($userId, 13);
            }
            if($withdrawsByUser[$userId]['c'] >= 1000000) {
                $this->batchAchievement($userId, 14);
            }
            $this->updateUserSetting($userId, 'totalearned-coins', json_encode($withdrawsByUser[$userId]['c']));
        }

        echo "\n".'done';

        return false;
    }

    public function checkshortlinkachievsAction()
    {
        $bCheck = true;
        if (!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if ($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if (!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $claimTbl = new TableGateway('shortlink_link_user', CoreEntityController::$oDbAdapter);

        $withdrawsByUser = [];
        $linksDoneThisMonth = [];
        $oWh = new Where();
        $oWh->notLike('date_completed', '0000-00-00 00:00:00');
        $claimSel = new Select($claimTbl->getTable());
        $claimSel->order('date_claimed ASC');
        $claimSel->where($oWh);
        $claimsDone = $claimTbl->selectWith($claimSel);
        foreach($claimsDone as $claim) {
            if(!array_key_exists($claim->user_idfs, $withdrawsByUser)) {
                $withdrawsByUser[$claim->user_idfs] = ['c' => 0];
            }
            $withdrawsByUser[$claim->user_idfs]['c']++;

            if(strtotime($claim->date_claimed) >= strtotime("first day of this month")) {
                if(!array_key_exists($claim->user_idfs, $linksDoneThisMonth)) {
                    $linksDoneThisMonth[$claim->user_idfs] = ['c' => 0];
                }
                $linksDoneThisMonth[$claim->user_idfs]['c']++;
            }
        }

        echo "Parsing Shortlinks of ".count($withdrawsByUser)." Users";
        echo "\nParsing Shortlinks of ".count($linksDoneThisMonth)." Users (this month)";

        foreach(array_keys($withdrawsByUser) as $userId) {
            $this->updateUserSetting($userId, 'shortlinks-total', json_encode($withdrawsByUser[$userId]['c']));
        }

        foreach(array_keys($linksDoneThisMonth) as $userId) {
            $this->updateUserSetting($userId, 'shortlinks-month', json_encode($linksDoneThisMonth[$userId]['c']));
        }

        echo "\n".'done';

        return false;
    }

    public function checkofferwallachievsAction()
    {
        $bCheck = true;
        if (!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if ($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if (!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $claimTbl = new TableGateway('offerwall_user', CoreEntityController::$oDbAdapter);

        $withdrawsByUser = [];
        $withdrawsByUserMonth = [];

        $claimSel = new Select($claimTbl->getTable());
        $claimSel->order('date_completed ASC');
        $claimsDone = $claimTbl->selectWith($claimSel);
        foreach($claimsDone as $claim) {
            if(!array_key_exists($claim->user_idfs, $withdrawsByUser)) {
                $withdrawsByUser[$claim->user_idfs] = ['c' => 0,'a' => 0,'cc' => 0];
            }
            $withdrawsByUser[$claim->user_idfs]['c']+=$claim->amount;
            $withdrawsByUser[$claim->user_idfs]['a']++;
            $withdrawsByUser[$claim->user_idfs]['cc']++;
            if(strtotime($claim->date_claimed) >= strtotime("first day of this month")) {
                if(!array_key_exists($claim->user_idfs, $withdrawsByUserMonth)) {
                    $withdrawsByUserMonth[$claim->user_idfs] = ['c' => 0];
                }
                $withdrawsByUserMonth[$claim->user_idfs]['c']++;
            }
        }

        echo "Parsing Offers done of ".count($withdrawsByUser)." Users";

        foreach(array_keys($withdrawsByUser) as $userId) {
            if($withdrawsByUser[$userId]['a'] >= 50) {
                $this->batchAchievement($userId, 19);
            }
            if($withdrawsByUser[$userId]['a'] >= 250) {
                $this->batchAchievement($userId, 20);
            }
            if($withdrawsByUser[$userId]['a'] >= 500) {
                $this->batchAchievement($userId, 21);
            }
            if($withdrawsByUser[$userId]['a'] >= 1000) {
                $this->batchAchievement($userId, 22);
            }

            $this->updateUserSetting($userId, 'totaloffers-coins', $withdrawsByUser[$userId]['c']);
            $this->updateUserSetting($userId, 'totaloffers-amount', $withdrawsByUser[$userId]['a']);
            $this->updateUserSetting($userId, 'totaloffers-count', $withdrawsByUser[$userId]['cc']);
        }

        foreach(array_keys($withdrawsByUserMonth) as $userId) {
            $this->updateUserSetting($userId, 'totaloffers-month', $withdrawsByUserMonth[$userId]['c']);
        }

        echo "\n".'done';

        return false;
    }

    public function checkbchpaymentsAction() {
        $bCheck = true;
        if(!isset($_REQUEST['authkey'])) {
            $bCheck = false;
        } else {
            $authKey = filter_var($_REQUEST['authkey'], FILTER_SANITIZE_STRING);
            if($authKey != CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $bCheck = false;
            }
        }

        if(!$bCheck) {
            return $this->redirect()->toRoute('home');
        }
        $this->layout('layout/json');

        $sBCHNodeUrl = CoreEntityController::$aGlobalSettings['bchnode-rpcurl'];
        $paymentTbl = new TableGateway('faucet_tokenbuy', CoreEntityController::$oDbAdapter);
        $payments = $paymentTbl->select(['received' => 0,'coin' => 'BCH']);
        if(count($payments) > 0) {

            echo 'processing '.count($payments).' payments';

            foreach($payments as $payment) {
                if($payment->wallet_receive != NULL) {
                    $wallRec = str_replace(['bitcoincash:'],[''],$payment->wallet_receive);
                    $client = new Client();
                    $client->setUri($sBCHNodeUrl);
                    $client->setMethod('POST');
                    $client->setRawBody('{"jsonrpc":"2.0","id":"curltext","method":"getreceivedbyaddress","params":["'.$wallRec.'"]}');
                    $response = $client->send();
                    $googleResponse = json_decode($response->getBody());
                    $walletReceive = (float)$googleResponse->result;
                    if($walletReceive == $payment->price) {
                        $paymentTbl->update([
                            'received' => 1,
                        ],[
                            'Buy_ID' => $payment->Buy_ID,
                        ]);
                        echo "\n".$wallRec.' = '.$walletReceive.' payment of '.$payment->price. 'received!';
                    } else {
                        echo "\n".$wallRec.' = '.$walletReceive.' should be '.$payment->price;
                    }
                } else {
                    echo "\n"."skip";
                }
            }
        } else {
            echo 'no payments to process currently';
        }

        echo "\n\n"."done";

        return false;
    }

    private function batchAchievement($userId, $achievId) {
        $achiev = $this->achievTbl->select(['Achievement_ID' => $achievId]);
        if(count($achiev) == 0) {
            return false;
        }
        $achievCheck = $this->achievDoneTbl->select([
            'user_idfs' => $userId,
            'achievement_idfs' => $achievId
        ]);
        if(count($achievCheck) == 0) {
            $achiev = $achiev->current();
            echo "\n"." User ".$userId." earned Achievement ".$achiev->label;
            $this->achievDoneTbl->insert([
                'user_idfs' => $userId,
                'achievement_idfs' => $achievId,
                'date' => date('Y-m-d H:i:s', time()),
            ]);
        }
    }

    /**
     * Execute Faucet Token Transaction for User
     *
     * @param float $amount - Amount of Token to transfer
     * @param bool $isInput - Defines if Transaction is Output
     * @param int $userId - Target User ID
     * @param int $refId - Reference ID for Transaction
     * @param string $refType - Reference Type for Transaction
     * @param string $description - Detailed Description for Transaction
     * @param int $createdBy (optional) - Source User ID
     * @since 1.0.0
     */
    private function executeTransaction(float $amount, bool $isOutput, int $userId, int $refId,
                                       string $refType, string $description, int $createdBy = 0)
    {
        $mTransTbl = new TableGateway('faucet_transaction', CoreEntityController::$oDbAdapter);
        $mUserTbl = new TableGateway('user', CoreEntityController::$oDbAdapter);

        # no negative transactions allowed
        if($amount < 0) {
            return false;
        }

        # Do not allow zero for update
        if($userId == 0) {
            return false;
        }

        # Generate Transaction ID
        try {
            $sTransactionID = $bytes = random_bytes(5);
        } catch(\Exception $e) {
            # Fallback if random bytes fails
            $sTransactionID = time();
        }
        $sTransactionID = hash("sha256",$sTransactionID);

        # Get user from database
        $userInfo = $mUserTbl->select(['User_ID' => $userId]);
        if(count($userInfo) > 0) {
            $userInfo = $userInfo->current();
            # calculate new balance
            $newBalance = ($isOutput) ? $userInfo->token_balance-$amount : $userInfo->token_balance+$amount;
            # Insert Transaction
            if($mTransTbl->insert([
                'Transaction_ID' => $sTransactionID,
                'amount' => $amount,
                'token_balance' => $userInfo->token_balance,
                'token_balance_new' => $newBalance,
                'is_output' => ($isOutput) ? 1 : 0,
                'date' => date('Y-m-d H:i:s', time()),
                'ref_idfs' => $refId,
                'ref_type' => $refType,
                'comment' => $description,
                'user_idfs' => $userId,
                'created_by' => ($createdBy == 0) ? $userId : $createdBy,
            ])) {
                # update user balance
                $mUserTbl->update([
                    'token_balance' => $newBalance,
                ],[
                    'User_ID' => $userId
                ]);
                return $newBalance;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * Execute Faucet Guild Token Transaction for User
     *
     * @param float $amount - Amount of Token to transfer
     * @param bool $isInput - Defines if Transaction is Output
     * @param int $guildId - Target Guild ID
     * @param int $refId - Reference ID for Transaction
     * @param string $refType - Reference Type for Transaction
     * @param string $description - Detailed Description for Transaction
     * @param int $createdBy (optional) - Source User ID
     * @since 1.0.0
     */
    private function executeGuildTransaction(float $amount, bool $isOutput, int $guildId, int $refId,
                                            string $refType, string $description, int $createdBy)
    {
        $mTransTbl = new TableGateway('faucet_guild_transaction', CoreEntityController::$oDbAdapter);
        $mGuildTbl = new TableGateway('faucet_guild', CoreEntityController::$oDbAdapter);

        # no negative transactions allowed
        if($amount < 0) {
            return false;
        }

        # Do not allow zero for update
        if($guildId == 0) {
            return false;
        }

        # Generate Transaction ID
        try {
            $sTransactionID = $bytes = random_bytes(5);
        } catch(\Exception $e) {
            # Fallback if random bytes fails
            $sTransactionID = time();
        }
        $sTransactionID = hash("sha256",$sTransactionID);

        # Get user from database
        $guildInfo = $mGuildTbl->select(['Guild_ID' => $guildId]);
        if(count($guildInfo) > 0) {
            $guildInfo = $guildInfo->current();
            # calculate new balance
            $newBalance = ($isOutput) ? $guildInfo->token_balance-$amount : $guildInfo->token_balance+$amount;
            # Insert Transaction
            if($mTransTbl->insert([
                'Transaction_ID' => $sTransactionID,
                'amount' => $amount,
                'token_balance' => $guildInfo->token_balance,
                'token_balance_new' => $newBalance,
                'is_output' => ($isOutput) ? 1 : 0,
                'date' => date('Y-m-d H:i:s', time()),
                'ref_idfs' => $refId,
                'ref_type' => $refType,
                'comment' => $description,
                'guild_idfs' => $guildId,
                'created_by' => $createdBy,
            ])) {
                # update user balance
                $mGuildTbl->update([
                    'token_balance' => $newBalance,
                ],[
                    'Guild_ID' => $guildId
                ]);
                return $sTransactionID;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    private function updateUserSetting($userId, $settingName, $settingValue) {
        $setCheck = $this->userSetTbl->select(['user_idfs' => $userId,'setting_name' => $settingName]);
        if(count($setCheck) == 0) {
            $this->userSetTbl->insert([
                'user_idfs' => $userId,'setting_name' => $settingName,'setting_value' => $settingValue]);
        } else {
            $this->userSetTbl->update([
                'setting_value' => $settingValue
            ],[
                'user_idfs' => $userId,'setting_name' => $settingName
            ]);
        }
    }
}
