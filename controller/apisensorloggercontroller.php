<?php

namespace OCA\SensorLogger\Controller;

use OC\OCS\Exception;
use OC\OCS\Result;
use OC\Share\Share;
use OCA\SensorLogger\DataType;
use OCA\SensorLogger\DataTypes;
use OCA\SensorLogger\SensorDevices;
use OCP\API;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * Class ApiSensorLoggerController
 *
 * @package OCA\SensorLogger\Controller
 */
class ApiSensorLoggerController extends ApiController {

	private $userId;
	private $db;

	/** @var IManager */
	private $shareManager;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IUserManager */
	private $userManager;
	/** @var IUser */
	private $currentUser;
	/** @var IL10N */
	private $l;

	protected $config;

	public function __construct($AppName,
								IRequest $request,
								IDBConnection $db,
								IConfig $config,
								IManager $shareManager,
								IGroupManager $groupManager,
								IUserManager $userManager,
								IL10N $l10n,
								$UserId) {
		parent::__construct(
			$AppName,
			$request,
			'PUT, POST, GET, DELETE, PATCH',
			'Authorization, Content-Type, Accept',
			1728000);
		$this->db = $db;
		$this->userId = $UserId;
		$this->config = $config;
		$this->shareManager = $shareManager;
		$this->userManager = $userManager;
		$this->groupManager = $groupManager;
		$this->l = $l10n;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function createLog() {
		$params = $this->request->getParams();
		if(isset($params['data'])) {
			$this->insertExtendedLog($params);
		} else {
			$this->insertLog($params);
		}

	}

	/**
	 * @param $array
	 * @return bool
	 */
	protected function insertExtendedLog($array) {
		$registered = $this->checkRegisteredDevice($array);
		if($registered) {
			$deviceId = $array['deviceId'];
			$dataJson = json_encode($array['data']);

			$sql = 'INSERT INTO `*PREFIX*sensorlogger_logs` (created_at,user_id,device_uuid,`data`) VALUES(?,?,?,?)';
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(1, $array['date']);
			$stmt->bindParam(2, $this->userId);
			$stmt->bindParam(3, $deviceId);
			$stmt->bindParam(4, $dataJson);
			$stmt->execute();
		}
		return true;
	}

	/**
	 * @param array $array
	 * @return bool
	 */
	protected function insertLog($array){
		$registered = $this->checkRegisteredDevice($array);
		if(isset($array['deviceId'])) {
			if(!$registered) {
				$registered = $this->insertDevice($array);
			}
		}
		if($registered || !isset($array['deviceId'])) {
			$deviceId = $array['deviceId'] ?: null;

			$dataJson = json_encode($array);

			$sql = 'INSERT INTO `*PREFIX*sensorlogger_logs` (temperature,humidity,created_at,user_id,device_uuid,`data`) VALUES(?,?,?,?,?,?)';
			$stmt = $this->db->prepare($sql);
			$stmt->bindParam(1, $array['temperature']);
			$stmt->bindParam(2, $array['humidity']);
			$stmt->bindParam(3, $array['date']);
			$stmt->bindParam(4, $this->userId);
			$stmt->bindParam(5, $deviceId);
			$stmt->bindParam(6, $dataJson);
			$stmt->execute();
		}
		return true;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function registerDevice() {
		if(!$this->checkRegisteredDevice($this->request->getParams()) &&
			$this->checkRegisteredDevice($this->request->getParams()) !== null) {

			$lastInsertId = $this->insertDevice($this->request->getParams());
			if(is_int($lastInsertId)) {
				$this->checkRequestParams($lastInsertId,$this->request->getParams());
			}
		} else {
			return true;
		}
		return false;
	}

	/**
	 * @param array $array
	 * @return int
	 */
	protected function insertDeviceType($array) {
		$sql = 'INSERT INTO `*PREFIX*sensorlogger_device_types` (`user_id`,`device_type_name`) VALUES(?,?)';
		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(1, $this->userId);
		$stmt->bindParam(2, $array['deviceType']);
		if($stmt->execute()){
			return (int)$this->db->lastInsertId();
		}
		return false;
	}

	/**
	 * @param string $string
	 * @return int
	 */
	protected function insertDeviceGroup($string) {
		$sql = 'INSERT INTO `*PREFIX*sensorlogger_device_groups` (`user_id`,`device_group_name`) VALUES(?,?)';
		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(1, $this->userId);
		$stmt->bindParam(2, $string);
		if($stmt->execute()){
			return (int)$this->db->lastInsertId();
		}
		return false;
	}

	/**
	 * @param array $array
	 * @return int
	 */
	protected function insertDataTypes($array) {
		$sql = 'INSERT INTO `*PREFIX*sensorlogger_data_types` (`user_id`,`description`,`type`,`short`) VALUES(?,?,?,?)';
		$stmt = $this->db->prepare($sql);

		if(isset($array['description'])) {
			$description = $array['description'] ?: '';
		} else {
			$description = '';
		}

		if(isset($array['type'])) {
			$type = $array['type'] ?: '';
		} else {
			$type = '';
		}

		if(isset($array['unit'])) {
			$unit = $array['unit'] ?: '';
		} else {
			$unit = '';
		}

		$stmt->bindParam(1, $this->userId);
		$stmt->bindParam(2, $description);
		$stmt->bindParam(3, $type);
		$stmt->bindParam(4, $unit);
		if($stmt->execute()){
			return (int)$this->db->lastInsertId();
		}
		return false;
	}

	/**
	 * @param int $deviceId
	 * @param array $params
	 */
	protected function checkRequestParams($deviceId,$params) {
		if(isset($params['deviceType']) && !empty($params['deviceType'])) {
			$deviceTypeId = $this->insertDeviceType($params);
			if(is_int($deviceTypeId)) {
				try {
					SensorDevices::updateDevice($deviceId,'type_id',(string)$deviceTypeId,$this->db);
				} catch (\Exception $e) {}
			}
		}
		if(isset($params['deviceGroup']) && !empty($params['deviceGroup'])) {
			$deviceGroupId = $this->insertDeviceGroup($params['deviceGroup']);
			if(is_int($deviceGroupId)) {
				try {
					SensorDevices::updateDevice($deviceId,'group_id',$deviceGroupId,$this->db);
				} catch (\Exception $e) {}
			}
		}
		if(isset($params['deviceParentGroup']) && !empty($params['deviceParentGroup'])) {
			$deviceGroupParentId = $this->insertDeviceGroup($params['deviceParentGroup']);
			if(is_int($deviceGroupParentId)) {
				try {
					SensorDevices::updateDevice($deviceId, 'group_parent_id', $deviceGroupParentId, $this->db);
				} catch (\Exception $e) {
				}
			}
		}
		if(isset($params['deviceDataTypes']) && is_array($params['deviceDataTypes'])) {
			foreach($params['deviceDataTypes'] as $key => $array){
				$availableDataTypes = DataTypes::getDataTypesByUserId($this->userId,$this->db);
				/** @var DataType $availableDataType */
				foreach($availableDataTypes as $availableDataType) {
					if($availableDataType->getShort() === $array['unit'] && $availableDataType->getType() === $array['type']) {
						$dataTypeId = $availableDataType->getId();
						if(is_int($dataTypeId)) {
							$this->insertDeviceDataTypes($deviceId,$dataTypeId);
						}
						continue 2;
					}
				}
				$dataTypeId = $this->insertDataTypes($array);
				if(is_int($dataTypeId)) {
					$this->insertDeviceDataTypes($deviceId,$dataTypeId);
				}
			}
		}
	}

	/**
	 * @param int $deviceId
	 * @param int $dataTypeId
	 */
	protected function insertDeviceDataTypes($deviceId,$dataTypeId){
		$sql = 'INSERT INTO `*PREFIX*sensorlogger_device_data_types` (`user_id`,`device_id`,`data_type_id`) VALUES(?,?,?)';
		$stmt = $this->db->prepare($sql);
		$stmt->bindParam(1, $this->userId);
		$stmt->bindParam(2, $deviceId);
		$stmt->bindParam(3, $dataTypeId);
		$stmt->execute();
	}

	/**
	 * @param $params
	 * @return bool;
	 */
	protected function checkRegisteredDevice($params) {
		if(isset($params['deviceId'])) {
			$deviceId = $params["deviceId"];
			$query = $this->db->getQueryBuilder();
			$query->select('*')
				->from('sensorlogger_devices')
				->where('uuid = "'.$deviceId.'" ');
			$query->setMaxResults(1);
			$result = $query->execute();

			$data = $result->fetchAll();

			if(count($data) < 1) {
				return false;
			} else {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array $array
	 * @return int|string
	 */
	protected function insertDevice($array) {
		$sql = 'INSERT INTO `*PREFIX*sensorlogger_devices` (`uuid`,`name`,`type_id`,`user_id`) VALUES(?,?,?,?)';
		$stmt = $this->db->prepare($sql);

		if(isset($array['deviceId'])) {
			if(!isset($array['deviceName'])) {
				$array['deviceName'] = 'Default device';
			}

			if(!isset($array['deviceTypeId'])) {
				$array['deviceTypeId'] = 0;
			}

			$stmt->bindParam(1, $array['deviceId']);
			$stmt->bindParam(2, $array['deviceName']);
			$stmt->bindParam(3, $array['deviceTypeId']);
			$stmt->bindParam(4, $this->userId);

			if($stmt->execute()){
				return (int)$this->db->lastInsertId();
			}
		} else {
			return 'Missing device ID';
		}
		return false;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @CORS
	 */
	public function getDeviceDataTypes(){
		$params = $this->request->getParams();
		$device = SensorDevices::getDeviceByUuid($this->userId,$params['deviceId'],$this->db);
		$dataTypes = DataTypes::getDeviceDataTypesByDeviceId($this->userId,$device->getId(),$this->db);
		//return json_encode($dataTypes);
		return $this->returnJSON($dataTypes);
	}

	/**
	 * Get all shared devices
	 */
	public function getAllShares() {
		# TODO [GH12] Add apisensorloggercontroller::getallshares
	}

	/**
	 * Share a device
	 */
	public function createShare() {
		# TODO [GH13] Add apisensorloggercontroller::createShare
	}

	/**
	 * Get a shared device by id
	 * @param $id
	 * @return Result
	 */
	public function getShare($id) {
		# TODO [GH14] Add apisensorloggercontroller::getShare
		if (!$this->shareManager->shareApiEnabled()) {
			return new Result(null, 404, $this->l->t('Share API is disabled'));
		}

		try {
			$share = $this->getShareById($id);
		} catch (ShareNotFound $e) {
			return new Result(null, 404, $this->l->t('Wrong share ID, share doesn\'t exist'));
		}

		if ($this->canAccessShare($share)) {
			try {
			} catch (Exception $e) {
			}
		}

		return new Result(null, 404, $this->l->t('Wrong share ID, share doesn\'t exist'));
	}

	/**
	 * @param $id
	 * @return null|IShare
	 * @throws ShareNotFound
	 */
	private function getShareById($id) {
		# TODO [GH15] Add apisensorloggercontroller::getShareById

		$share = null;

		try {
			$share = $this->shareManager->getShareById('ocinternal:'.$id);
		} catch (ShareNotFound $e) {
			if (!$this->shareManager->outgoingServer2ServerSharesAllowed()) {
				throw new ShareNotFound();
			}
			$share = $this->shareManager->getShareById('ocFederatedSharing:' . $id);
		}

		return $share;
	}

	/**
	 * Update shared device
	 */
	public function updateShare() {
		# TODO [GH16] Add apisensorloggercontroller::updateShare
	}

	/**
	 * Delete a shared device
	 */
	public function deleteShare() {
		# TODO [GH17] Add apisensorloggercontroller::deleteshare
	}

	/**
	 * @param IShare $share
	 * @return bool
	 */
	protected function canAccessShare(IShare $share) {
		// A file with permissions 0 can't be accessed by us. So Don't show it
		if ($share->getPermissions() === 0) {
			return false;
		}

		// Owner of the file and the sharer of the file can always get share
		if ($share->getShareOwner() === $this->currentUser->getUID() ||
			$share->getSharedBy() === $this->currentUser->getUID()
		) {
			return true;
		}

		// If the share is shared with you (or a group you are a member of)
		if ($share->getShareType() === Share::SHARE_TYPE_USER &&
			$share->getSharedWith() === $this->currentUser->getUID()) {
			return true;
		}

		if ($share->getShareType() === Share::SHARE_TYPE_GROUP) {
			$sharedWith = $this->groupManager->get($share->getSharedWith());
			if (!is_null($sharedWith) && $sharedWith->inGroup($this->currentUser)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param $array
	 * @return DataResponse
	 */
	public function returnJSON($array) {
		try {
			return new DataResponse($array);
		} catch (\Exception $ex) {
			return new DataResponse(array('msg' => 'not found!'), API::RESPOND_NOT_FOUND);
		}
	}
}