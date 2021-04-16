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
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
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

    /**
     * Generate Stats for Hall of Fame
     *
     * @return false
     * @since 1.0.0
     */
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

        $oWh = new Where();
        $oWh->greaterThan('xp_level', 1);
        $aUsers = $this->fetchCustomTable('user', $oWh);


        $aSkipUsers = ['335874987' => true];
        $aUsersByIncome = [];
        $aUsersByID = [];
        foreach($aUsers as $oUsr) {
            if(array_key_exists($oUsr->User_ID,$aSkipUsers)) {
                continue;
            }
            $fTotalIncome = 0;

            $oWh = new Where();
            $oWh->equalTo('user_idfs', $oUsr->User_ID);

            $oPtcsDone = $oAdsDoneTbl->select($oWh);
            if(count($oPtcsDone) > 0) {
                foreach($oPtcsDone as $oPtcD) {
                    $oPtc = $oAdsTbl->select(['PTC_ID' => $oPtcD->ptc_idfs])->current();
                    $fTotalIncome+=$oPtc->reward;
                }
            }

            $oShortsDone = $oMyLinksDoneTbl->select($oWh);
            if(count($oShortsDone) > 0) {
                foreach($oShortsDone as $oShD) {
                    $oSh = $oProvidersTbl->select(['Shortlink_ID' => $oShD->shortlink_idfs])->current();
                    $fTotalIncome+=$oSh->reward;
                }
            }

            $oWallsDone = $oWallsDoneTbl->select($oWh);
            if(count($oWallsDone) > 0) {
                foreach($oWallsDone as $oWallD) {
                    $fTotalIncome+=$oWallD->amount;
                }
            }

            $oClaimsDone = $oClaimTbl->select($oWh);
            if(count($oClaimsDone) > 0) {
                foreach($oClaimsDone as $oClaimD) {
                    if($oClaimD->mode == 'coins') {
                        $fTotalIncome+=$oClaimD->amount;
                    }
                }
            }

            $oWhWin = new Where();
            $oWhWin->equalTo('winner_idfs', $oUsr->User_ID);
            $oGamesWon = $oGameTbl->select($oWhWin);
            if(count($oGamesWon) > 0) {
                foreach($oGamesWon as $oGameD) {
                    $fTotalIncome+=$oGameD->amount_bet;
                }
            }

            $oMinerShares = $oMinerTbl->select($oWh);
            if(count($oMinerShares) > 0) {
                foreach($oMinerShares as $oShare) {
                    $fTotalIncome+=$oShare->amount_coin;
                }
            }

            $aUsersByIncome[$oUsr->User_ID] = $fTotalIncome;
            $aUsersByID[$oUsr->User_ID] = $oUsr->username;
        }

        arsort($aUsersByIncome);

        $aTopEarners = [];

        $iCount = 1;
        foreach(array_keys($aUsersByIncome) as $iUsrID) {
            if($iCount == 6) {
                break;
            }
            $aTopEarners[$iCount] = (object)['id' => $iUsrID,'coins' => $aUsersByIncome[$iUsrID]];
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
        if (isset($_REQUEST['authkey'])) {
            if (strip_tags($_REQUEST['authkey']) == CoreEntityController::$aGlobalSettings['batch-serverkey']) {
                $this->layout('layout/json');

                $url = 'https://pro-api.coinmarketcap.com/v1/cryptocurrency/quotes/latest';
                $parameters = [
                    'slug' => 'bitcoin,ethereum,ethereum-classic,ravencoin,groestlcoin,bitcoin-cash,dogecoin,binance-coin,litecoin',
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
}
