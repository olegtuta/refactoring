<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    private array $initResult = [
        'notificationEmployeeByEmail' => false,
        'notificationClientByEmail'   => false,
        'notificationClientBySms'     => [
            'isSent'  => false,
            'message' => '',
        ],
    ];

    /**
     * Основной метод для выполнения операции
     *
     * @return void
     * @throws Exception
     */
    public function doOperation(): void
    {
        $data = (array)$this->getRequest('data');
        $result = $this->initResult;

        $this->validateRequestData($data);

        $resellerId = (int)$data['resellerId'];
        $notificationType = (int)$data['notificationType'];
        $reseller = $this->getSeller($resellerId);
        $client = $this->getClient((int)$data['clientId'], $resellerId);
        $creator = $this->getEmployee((int)$data['creatorId']);
        $expert = $this->getEmployee((int)$data['expertId']);

        $differences = $this->getDifferences($notificationType, $data);
        $templateData = $this->prepareTemplateData($data, $creator, $expert, $client, $differences);
        $this->validateTemplateData($templateData);

        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');

        $this->notifyEmployees($emails, $emailFrom, $templateData, $resellerId, $result);
        $this->notifyClient($notificationType, $data, $emailFrom, $client, $templateData, $resellerId, $result);
    }

    /**
     * Валидация данных запроса
     *
     * @param array $data
     * @throws Exception
     */
    private function validateRequestData(array $data): void
    {
        if (empty($data['resellerId']) || empty($data['notificationType'])) {
            throw new Exception('Empty resellerId or notificationType', 400);
        }
    }

    /**
     * Получение информации о продавце
     *
     * @param int $resellerId
     * @return Contractor
     * @throws Exception
     */
    private function getSeller(int $resellerId): Contractor
    {
        return Seller::getById($resellerId);
    }

    /**
     * Получение информации о клиенте
     *
     * @param int $clientId
     * @param int $resellerId
     * @return Contractor
     * @throws Exception
     */
    private function getClient(int $clientId, int $resellerId): Contractor
    {
        $client = Contractor::getById($clientId);
        if ($client->getType() !== Contractor::TYPE_CUSTOMER || $client->Seller->getId() !== $resellerId) {
            throw new Exception('Client not found!', 400);
        }
        return $client;
    }

    /**
     * Получение информации о сотруднике
     *
     * @param int $employeeId
     * @return Contractor
     * @throws Exception
     */
    private function getEmployee(int $employeeId): Contractor
    {
        return Employee::getById($employeeId);
    }

    /**
     * Получение различий для уведомления
     *
     * @param int $notificationType
     * @param array $data
     * @return string
     */
    private function getDifferences(int $notificationType, array $data): string
    {
        $resellerId = (int)$data['resellerId'];

        if ($notificationType === self::TYPE_NEW) {
            return __('NewPositionAdded', [], $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO'   => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }
        return '';
    }

    /**
     * Подготовка данных шаблона для уведомления
     *
     * @param array $data
     * @param Contractor $creator
     * @param Contractor $expert
     * @param Contractor $client
     * @param string $differences
     * @return array
     */
    private function prepareTemplateData(
        array $data,
        Contractor $creator,
        Contractor $expert,
        Contractor $client,
        string $differences
    ): array
    {
        $clientFullName = $client->getFullName() ?: $client->name;

        return [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$data['complaintNumber'],
            'CREATOR_ID'         => (int)$data['creatorId'],
            'CREATOR_NAME'       => $creator->getFullName(),
            'EXPERT_ID'          => (int)$data['expertId'],
            'EXPERT_NAME'        => $expert->getFullName(),
            'CLIENT_ID'          => (int)$data['clientId'],
            'CLIENT_NAME'        => $clientFullName,
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$data['agreementNumber'],
            'DATE'               => (string)$data['date'],
            'DIFFERENCES'        => $differences,
        ];
    }

    /**
     * Валидация данных шаблона
     *
     * @param array $templateData
     * @throws Exception
     */
    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    /**
     * Уведомление сотрудников
     *
     * @param array $emails
     * @param string $emailFrom
     * @param array $templateData
     * @param int $resellerId
     * @param array &$result
     */
    private function notifyEmployees(array $emails, string $emailFrom, array $templateData, int $resellerId, array &$result): void
    {
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                try {
                    $messages = [
                        [
                            'emailFrom' => $emailFrom,
                            'emailTo'   => $email,
                            'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                            'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                        ],
                    ];
                    MessagesClient::sendMessage(
                        $messages,
                        $resellerId,
                        null,
                        NotificationEvents::CHANGE_RETURN_STATUS,
                        null
                    );
                    $result['notificationEmployeeByEmail'] = true;
                } catch (Exception $e) {
                    error_log("Error sending email to employee: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Уведомление клиента
     *
     * @param int $notificationType
     * @param array $data
     * @param string $emailFrom
     * @param Contractor $client
     * @param array $templateData
     * @param int $resellerId
     * @param array &$result
     */
    private function notifyClient(
        int $notificationType,
        array $data,
        string $emailFrom,
        Contractor $client,
        array $templateData,
        int $resellerId,
        array &$result
    ): void
    {
        if (
            ($notificationType !== self::TYPE_CHANGE && empty($data['differences']['to'])) ||
            (empty($emailFrom) && empty($client->getEmail()))
        ) {
            return;
        }

        $error = '';

        try {
            $messages = [
                [
                    'emailFrom' => $emailFrom,
                    'emailTo'   => $client->getEmail(),
                    'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                    'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                ],
            ];
            MessagesClient::sendMessage(
                $messages,
                $resellerId,
                $client->getId(),
                NotificationEvents::CHANGE_RETURN_STATUS,
                (int)$data['differences']['to']
            );
            $result['notificationClientByEmail'] = true;
        } catch (Exception $e) {
            $error = $e->getMessage();
            error_log("Error sending email to client: " . $e->getMessage());
        }

        if (empty($client->mobile)) {
            return;
        }

        try {
            $res = NotificationManager::send(
                $resellerId,
                $client->getId(),
                NotificationEvents::CHANGE_RETURN_STATUS,
                (int)$data['differences']['to'],
                $templateData,
                $error,
            );
            if ($res) {
                $result['notificationClientBySms']['isSent'] = true;
            }
            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        } catch (Exception $e) {
            error_log("Error sending SMS to client: " . $e->getMessage());
        }
    }
}
