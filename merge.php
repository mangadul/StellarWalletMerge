<?php

/**
 *
 *  Tools for merge wallet, features include send all left assets balance back to issuer, remove trustline and merge wallet
 *  This script read your private key in pvk.txt file line by line
 *  Using library stellar-php-sdk from soneso -> https://github.com/Soneso/stellar-php-sdk
 *  For more example please look at https://github.com/Soneso/stellar-php-sdk/tree/main/examples
 * 
 *  Bugs found when call `requestAccount` when account not available or deleted 
 *  ---------------------
 *  PHP Fatal error:  Uncaught GuzzleHttp\Exception\ClientException: 
 *  Client error: `GET https://horizon.stellar.org/accounts/WALLET_ADDRESS` resulted in a `404 Not Found` response
 *  Error still appear even use guzzle error handling or exception
 *  Suggestion: please fix it in your code using $promise->wait() method inside (try-catch) try and then catch the exception
 * 
 * 
 *  By @mangadul
 * 
 **/

require_once __DIR__ . '/vendor/autoload.php';

use Soneso\StellarSDK\StellarSDK;
use Soneso\StellarSDK\Crypto\KeyPair;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\AssetTypeCreditAlphanum4;
use Soneso\StellarSDK\AssetTypeCreditAlphaNum12;
use Soneso\StellarSDK\ChangeTrustOperationBuilder;
use Soneso\StellarSDK\CreateAccountOperationBuilder;
use Soneso\StellarSDK\ManageDataOperationBuilder;
use Soneso\StellarSDK\AccountMergeOperationBuilder;
use Soneso\StellarSDK\Network;
use Soneso\StellarSDK\PaymentOperationBuilder;
use Soneso\StellarSDK\Responses\Operations\CreateAccountOperationResponse;
use Soneso\StellarSDK\Responses\Operations\PaymentOperationResponse;
use Soneso\StellarSDK\TransactionBuilder;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ClientErrorResponseException;
use GuzzleHttp\Exception\RequestException;

$sdk = StellarSDK::getPublicNetInstance();

function getAccount($sdk, $pvk, $accountId) {
    $to = "PRIVATE_KEY_DESTINATION_TO_MERGE_WALLET";

    #echo $accountId.PHP_EOL;

    try {
        $account = $sdk->requestAccount($accountId);
        print(PHP_EOL."account still exists: ".PHP_EOL);        
        if ($sdk->accountExists($accountId)) {
            foreach ($account->getBalances() as $balance) {
                switch ($balance->getAssetType()) {
                    case Asset::TYPE_NATIVE:
                        printf (PHP_EOL."Balance: %s XLM", $balance->getBalance());
                        if (floatval($balance->getBalance()) > 0) {
                            mergeWallet($sdk, $to, $pvk);             
                        }
                        break;
                    default:
                        $assetCode = $balance->getAssetCode();
                        $assetIssuer = $balance->getAssetIssuer();
                        $bal = $balance->getBalance();
                        printf(PHP_EOL."Balance: %s %s Issuer: %s".PHP_EOL, $bal, $assetCode, $assetIssuer);
                        $lencode = strlen($balance->getAssetCode());
                        if (floatval($balance->getBalance()) > 0) {
                            sendBalance($sdk, $pvk, $assetCode, $assetIssuer, $bal, $assetIssuer, $lencode);                            
                        }
                        removeTrustLine($sdk, $pvk, $assetCode, $assetIssuer, $lencode);
                }
            }
        }

    } catch(GuzzleHttp\Exception\ClientException $e) {
        echo $e->getMessage();
    } catch(HorizonRequestException $e) {
        if($e->getCode() == 404) {
            print(PHP_EOL."success, account ".$account." not found".PHP_EOL);
        }
    }

}


function sendBalance($sdk, $pvk, $assetCode, $issuerAccountId, $balance, $destination, $lencode) {
    $senderKeyPair = KeyPair::fromSeed($pvk);
    $senderPub = $senderKeyPair->getAccountId();    
    $sender = $sdk->requestAccount($senderKeyPair->getAccountId());
    if($lencode > 4) {
        $assets = new AssetTypeCreditAlphaNum12($assetCode, $issuerAccountId);    
    } else {
        $assets = new AssetTypeCreditAlphanum4($assetCode, $issuerAccountId);    
    }
    $paymentOperation = (new PaymentOperationBuilder($destination, $assets, sprintf("%s", $balance)))->build();
    $transaction = (new TransactionBuilder($sender))->addOperation($paymentOperation)->build();    
    $transaction = sign($sender, $senderKeyPair, $transaction);    
    $response = $sdk->submitTransaction($transaction);
    if ($response->isSuccessful()) {
        print(PHP_EOL."Payment sent".PHP_EOL);
    }
}


function removeTrustLine($sdk, $signer, $assetCode, $issuerAccountId, $lencode){
    $limit = "0";
    $trustorKeypair = KeyPair::fromSeed($signer);
    $trustorAccountId = $trustorKeypair->getAccountId();    
    $trustorAccount =  $sdk->requestAccount($trustorAccountId);    
    if($lencode > 4) {
        $aqua = new AssetTypeCreditAlphaNum12($assetCode, $issuerAccountId);    
    } else {
        $aqua = new AssetTypeCreditAlphanum4($assetCode, $issuerAccountId);    
    }
    $cto = (new ChangeTrustOperationBuilder($aqua, $limit))->build();
    $transaction = (new TransactionBuilder($trustorAccount))->addOperation($cto)->build();    
    $transaction = sign($trustorAccount, $trustorKeypair, $transaction);    
    $sdk->submitTransaction($transaction);
    $trustorAccount = $sdk->requestAccount($trustorAccountId);
    $found = false;
    foreach ($trustorAccount->getBalances() as $balance) {
        if ($balance->getAssetCode() == $assetCode) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        print(PHP_EOL."success, trustline deleted".PHP_EOL);
    }
}

function mergeWallet($sdk, $to, $from) {
    print(PHP_EOL."merge wallet ...".PHP_EOL);
    $keyPairX = KeyPair::fromSeed(sprintf("%s", $to));    
    $keyPairY = KeyPair::fromSeed(sprintf("%s", $from));
    $accountXId = $keyPairX->getAccountId();
    $accountYId = $keyPairY->getAccountId();    
    $accMergeOp = (new AccountMergeOperationBuilder($accountXId))->build();    
    $accountY = $sdk->requestAccount($accountYId);    
    $transaction = (new TransactionBuilder($accountY))->addOperation($accMergeOp)->build();
    $sig_acct =  $sdk->requestAccount($accountYId);    
    $transaction = sign($sig_acct, $keyPairY, $transaction);    
    $response = $sdk->submitTransaction($transaction);
    if ($response->isSuccessful()) {
        print(PHP_EOL."successfully merged".PHP_EOL);
    }
}

function sign($acct, $signnn, $transaction){
    $gs = getSigner($acct);

    $temp_signer = array(
        'PUBLIC_KEY_FOR_MULTIPLE_SIGNER' => 'PRIVATE_KEY_FOR_MULTIPLE_SIGNER',
    );
        
    $pk = $signnn->getSecretSeed();
    $acctid = $signnn->getAccountId();
    $temp_signer = array_merge(array($acctid => $pk), $temp_signer);

    if(count($gs) ==1 ) {
        $transaction->sign($signnn, Network::public());
        return $transaction;
    } else {
        foreach($gs as $sg)
        {
            if (isset($temp_signer[$sg])) {
                echo "signer -> ".$temp_signer[$sg].PHP_EOL;   
                $signerKeyPair = KeyPair::fromSeed($temp_signer[$sg]);            
                $transaction->sign($signerKeyPair, Network::public());                    
            }
            sleep(1);
        }
        return $transaction; 
    }
}

function getSigner($account){
    $i = 0;
    $sug = [];
    foreach ($account->getSigners() as $sig) {
        $sug[] = $sig->getKey();
        $i++;
    }
    return $sug;
}

function isExist($sdk, $accountId){
    try {
        $account = $sdk->requestAccount($accountId);
    } catch (ClientException $e) {
        return false;
    } catch(HorizonRequestException $e){
        return false;
    } catch (RequestException $e) {
        return false;
    } catch (RuntimeException $e) {
        return false;
    }
    return true;    
}

# run application
# create file pvk.txt contains your private key, enter line by line
if ($file = fopen("pvk.txt", "r")) {
    while(!feof($file)) {
        $pvk = fgets($file);
        echo $pvk.PHP_EOL;
        $keyAcct = KeyPair::fromSeed(sprintf("%s", $pvk));  
        try {
            $accountID = $keyAcct->getAccountId(); 
            if(isExist($sdk, $accountID)) {
                getAccount($sdk, $pvk, $accountID);            
            }
        } catch (ClientException $e) {
            // catches all ClientExceptions
        } catch (RequestException $e) {
            // catches all RequestException
        } catch (HorizonRequestException $e) {
            // catches all HorizonRequestException
        } catch (RuntimeException $e) {
            // catches all RuntimeException
        }        
        
     }
    fclose($file);
}
