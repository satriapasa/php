<?php

require __DIR__ . '/Thrift/Library/TalkService.php';
require __DIR__ . '/Thrift/Library/ChannelService.php';
require_once __DIR__ . '/Thrift/Library/Types.php';

require_once __DIR__ . '/Thrift/ClassLoader/ThriftClassLoader.php';
require_once __DIR__ . '/Thrift/Exception/TException.php';
require __DIR__ . '/Thrift/Math/BigInteger.php';
require __DIR__ . '/Thrift/Crypt/RSA.php';

use Thrift\ClassLoader\ThriftClassLoader;

$loader = new ThriftClassLoader();
$loader->registerNamespace('Thrift', __DIR__);
$loader->register();

use Thrift\Protocol\TCompactProtocol;
use Thrift\Transport\TBufferedTransport;
use Thrift\Transport\TSocket;
use Thrift\Transport\THttpClient;
use Thrift\Transport\TTransport;
use Thrift\Exception\TException;

class Line {

	protected $auth_query_path = '/api/v4/TalkService.do';
	protected $auth_query_path2 = '/api/v4p/rs';
	protected $keys_get_path = '/authct/v1/keys/line';
	protected $http_query_path = '/S4';
	protected $poll_query_path = '/P4';
	protected $chan_query_path = '/CH4';
	protected $wait_for_mobile_path = '/Q';
	protected $host = 'gd2.line.naver.jp';

/*
	protected $UserAgent = 'DESKTOP:MAC:10.10.2-YOSEMITE-x64(4.5.0)';
	protected $AppName = 'DESKTOPMAC 10.10.2-YOSEMITE-x64    MAC 4.5.0';

*/
	protected $UserAgent = 'Android Mobile Line/7.18.1';
	protected $AppName = 'DESKTOPWIN\t7.18.1\tFDLRCN\t11.2.5';
	protected $port = 443;
	protected $systemname = 'PHP TEST';

	public function __construct($authToken = NULL, $mail = NULL, $password = NULL, $certificate = NULL) {
		$this->login = new stdClass;
		$this->poll = new stdClass;
		$this->transport = new stdClass;
		$this->protocol = new stdClass;

		if (empty($authToken)) {
			if (!empty($mail) && !empty($password)) {
				$this->login->transport = new THttpClient($this->host, $this->port, $this->auth_query_path, 'https');
				$this->login->transport->addHeaders(
						array(
							"User-Agent" => $this->UserAgent,
							"X-Line-Application" => $this->AppName,));
				$this->login->protocol = new TCompactProtocol($this->login->transport);
				$this->login->client = new TalkServiceClient($this->login->protocol);
				$this->login->rsakey = $this->login->client->getRSAKeyInfo(1);
				$this->login->msg = utf8_encode(chr(mb_strlen($this->login->rsakey->sessionKey)) . $this->login->rsakey->sessionKey . chr(mb_strlen($mail)) . $mail . chr(mb_strlen($password)) . $password);
				$this->login->crypted = $this->export_rsa($this->login->msg, $this->login->rsakey->nvalue, $this->login->rsakey->evalue);

				$LR = new loginRequest();
				$LR->type = 0;
				$LR->identityProvider = 1;
				$LR->identifier = $this->login->rsakey->keynm;
				$LR->password = $this->login->crypted;
				$LR->certificate = $certificate;
				$LR->accessLocation = "127.0.0,1";
				$LR->keepLoggedIn = 1;
				$LR->systemName = $this->systemname;
				$LR->e2eeVersion = 1;

				$this->login->transport = new THttpClient($this->host, $this->port, $this->auth_query_path2, 'https');
				$this->login->transport->addHeaders(
						array(
							"User-Agent" => $this->UserAgent,
							"X-Line-Application" => $this->AppName,));
				$this->login->protocol = new TCompactProtocol($this->login->transport);
				$this->login->client = new TalkServiceClient($this->login->protocol);

				$this->login->result = $this->login->client->loginZ($LR);

				switch ($this->login->result->type) {
					case 3: //pin required
						echo('type to your divice [' . $this->login->result->pinCode . ']' . PHP_EOL);
						$this->login->header = array(
							"X-Line-Access: {$this->login->result->verifier}"
						);
						$this->login->ch = curl_init();
						$this->login->options = array(
							CURLOPT_URL => 'https://' . $this->host . $this->wait_for_mobile_path,
							CURLOPT_HTTPHEADER => $this->login->header,
							CURLOPT_RETURNTRANSFER => true,
							CURLOPT_CUSTOMREQUEST => 'GET',
						);
						curl_setopt_array($this->login->ch, $this->login->options);
						$this->login->res = curl_exec($this->login->ch);
						curl_close($this->login->ch);
						$this->login->json_data = json_decode($this->login->res, true);
						$this->login->vr = $this->login->json_data["result"]["verifier"];

						$LR = new loginRequest();
						$LR->type = 1;
						$LR->verifier = $this->login->vr;
						$LR->e2eeVersion = 1;

						$this->login->log_in = $this->login->client->loginZ($LR);

						echo('authToken = ' . $this->login->log_in->authToken . PHP_EOL);
						echo('certificate = ' . $this->login->log_in->certificate . PHP_EOL);

						$this->LoginWhithAuth($this->login->log_in->authToken);
						$this->login = NULL;

					case 2: //None
						exit();

					case 1: //certificate
						$this->authToken = $this->login->result->authToken;
						echo('authToken = ' . $this->authToken);
						$this->LoginWhithAuth($this->authToken);
						$this->login = NULL;

				}
			} else {
				$this->login->transport = new THttpClient($this->host, $this->port, $this->auth_query_path, 'https');
				$this->login->transport->addHeaders(
						array(
							"User-Agent" => $this->UserAgent,
							"X-Line-Application" => $this->AppName,));
				$this->login->protocol = new TCompactProtocol($this->login->transport);
				$this->login->client = new TalkServiceClient($this->login->protocol);
				$this->login->msg = $this->login->client->getAuthQrcode(true, $this->systemname);

				$qrcode = $this->login->msg->qrcode;
				$verifier = $this->login->msg->verifier;
				echo("line://au/q/" . $verifier . PHP_EOL);
				$headers = array(
					"User-Agent: {$this->UserAgent}",
					"X-Line-Application: {$this->AppName}",
					"X-Line-Access: " . $verifier);
				$ch = curl_init('https://gd2.line.naver.jp/Q');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
				$res = json_decode(curl_exec($ch), true);
				curl_close($ch);

				$verifier = $res['result']['verifier'];
				try {
					$this->login->transport = new THttpClient($this->host, $this->port, $this->auth_query_path2, 'https');
					$this->login->transport->addHeaders(
							array(
								"User-Agent" => $this->UserAgent,
								"X-Line-Application" => $this->AppName,));
					$this->login->protocol = new TCompactProtocol($this->login->transport);
					$this->login->client = new TalkServiceClient($this->login->protocol);
					$LR = new loginRequest();

					$LR->type = 1;
					$LR->verifier = $verifier;
					$LR->e2eeVersion = 1;

					$this->login->msg = $this->login->client->loginZ($LR);

				} catch (TalkException $e) {
					echo $e->reason . PHP_EOL;
					exit;
				}
				$this->authToken = $this->login->msg->authToken;

				echo('authToken = ' . $this->authToken);
				echo('certificate = ' . $this->login->msg->certificate);

				$this->LoginWhithAuth($this->authToken);
				$this->login = NULL;
			}
		} else {
			try {
				$this->LoginWhithAuth($authToken);
			} catch (TalkException $e) {
				echo $e->reason . PHP_EOL;
				exit;
			}
		}
	}

	public function LoginWhithAuth($authToken) {
		$this->authToken = $authToken;
		$this->transport = new THttpClient($this->host, $this->port, $this->http_query_path, 'https');
		$this->transport->addHeaders(
				array(
					"User-Agent" => $this->UserAgent,
					"X-Line-Application" => $this->AppName,
					'X-Line-Access' => $this->authToken,
		));
		$this->protocol = new TCompactProtocol($this->transport);
		$this->client = new TalkServiceClient($this->protocol);
		$this->Poll = new Poll($this->authToken, $this->UserAgent, $this->AppName, $this->host, $this->port, $this->poll_query_path, $this->client);
		$this->Chan = new Chan($this->authToken, $this->UserAgent, $this->AppName, $this->host, $this->port, $this->chan_query_path, $this->client);

	}

	private function export_rsa($message, $n, $e) {
		$RSA = new Crypt_RSA();
		$A = new Math_BigInteger($n, 16);
		$B = new Math_BigInteger($e, 16);
		$public_key = $RSA->_convertPublicKey($A, $B);
		$RSA->setPublicKey($public_key);
		$pubkey = openssl_get_publickey($public_key);
		$msg = openssl_public_encrypt($message, $crypted, $pubkey);
		$crypted = bin2hex($crypted);

		return $crypted;
	}

	public function getGroupIdsInvited() {
		return $this->client->getGroupIdsInvited();
	}

	public function getUserTicket() {
		return $this->client->getUserTicket();
	}

	public function findAndAddContactsByMid($mid) {
		return $this->client->findAndAddContactsByMid(0, $mid);
	}

	public function getProfile() {
		return $this->client->getProfile();
	}

	public function getCompactGroup($groupId) {
		return $this->client->getCompactGroup($groupId);
	}

	public function getAuthQrcode($keepLoggedIn, $systemName) {
		return $this->client->getAuthQrcode($keepLoggedIn, $systemName);
	}

	public function getContacts($Mids) {
		return $this->client->getContacts($Mids);
	}

	public function acceptGroupInvitation($groupId) {
		return $this->client->acceptGroupInvitation(0, $groupId);
	}

	public function leaveGroup($groupId) {
		return $this->client->leaveGroup(0, $groupId);
	}

	public function kickoutFromGroup($groupId, $contactIds) {
		return $this->client->kickoutFromGroup(0, $groupId, $contactIds);
	}

	public function getGroup($groupId) {
		return $this->client->getGroup($groupId);
	}

	public function cancelGroupInvitation($groupId, $contactIds) {
		return $this->client->cancelGroupInvitation(0, $groupId, $contactIds);
	}

	public function sendMessage($text, $id) {
		$message = new Message();
		$message->text = $text;
		$message->to = $id;
		return $this->client->sendMessage(0, $message);
	}

	public function updateGroup($group) {
		return $this->client->updateGroup(0, $group);
	}

	public function getFavoriteMids() {
		return $this->client->getFavoriteMids();
	}

	public function inviteIntoGroup($groupId, $contactIds) {
		return $this->client->inviteIntoGroup(0, $groupId, $contactIds);
	}

	public function createGroup($name, $contactIds) {
		return $this->client->createGroup(0, $name, $contactIds);
	}

	public function leaveRoom($roomId) {
		return $this->client->leaveRoom(0, $roomId);
	}

	public function updateProfile($profile) {
		return $this->client->updateProfile(0, $profile);
	}

	public function updateRegion($region) {
		return $this->client->updateRegion($region);
	}

	public function getLastReadMessageIds($chatId) {
		return $this->client->getLastReadMessageIds($chatId);
	}

	public function getMessageBoxWrapUp($mid) {
		return $this->client->getMessageBoxWrapUp($mid);
	}

	public function getMessageBoxCompactWrapUpList($start, $messageBoxCount) {
		return $this->client->getMessageBoxCompactWrapUpList($start, $messageBoxCount);
	}

	public function getSettings() {
		return $this->client->getSettings();
	}

	public function getServerTime() {
		return $this->client->getServerTime();
	}

	public function reissueGroupTicket($groupId) {
		return $this->client->reissueGroupTicket($groupId);
	}

	public function findGroupByTicket($ticketId) {
		return $this->client->findGroupByTicket($ticketId);
	}

	public function acceptGroupInvitationByTicket($groupId, $ticketId) {
		return $this->client->acceptGroupInvitationByTicket(0, $groupId, $ticketId);
	}

}

class Poll {

	public function __construct($authToken, $UserAgent, $AppName, $host, $port, $poll_query_path, $Line) {
		$this->transport = new THttpClient($host, $port, $poll_query_path, 'https');
		$this->transport->addHeaders(
				array(
					"User-Agent" => $UserAgent,
					"X-Line-Application" => $AppName,
					'X-Line-Access' => $authToken,
		));
		$this->protocol = new TCompactProtocol($this->transport);
		$this->client = new TalkServiceClient($this->protocol);
		$this->revision = $Line->getLastOpRevision();
		file_put_contents(__DIR__ . '/revision.txt', $this->revision);
	}

	public function start($count = 1) {
		return $this->client->fetchOps($this->revision, $count, 0, 0);
	}

}


class Chan {

	public function __construct($authToken ,$UserAgent, $AppName , $host, $port, $chan_query_path, $Line) {
		$this->transport = new THttpClient($host, $port, $chan_query_path, 'https');
		$this->transport->addHeaders(
				array(
					"User-Agent" => $UserAgent,
					"X-Line-Application" => $AppName,
					'X-Line-Access' => $authToken,
		));
		$this->protocol = new TCompactProtocol($this->transport);
		$this->client = new ChannelServiceClient($this->protocol);
	}

	public function issueChannelToken($channelId){
		return $this->client->issueChannelToken($channelId);
	}

}
