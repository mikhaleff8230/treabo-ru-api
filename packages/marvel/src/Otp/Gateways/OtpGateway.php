<?php

namespace Marvel\Otp\Gateways;

use Marvel\Otp\OtpInterface;

/**
 * SmsGateway class
 * Bind to SmsGatewayInterface
 */
class OtpGateway
{
	private $gateway;

	public function __construct(OtpInterface $gateway)
	{
		$this->gateway = $gateway;
	}

	public function startVerification($phone_number)
	{
		return $this->gateway->startVerification($phone_number);
	}

	public function startVerificationVia($phone_number, string $channel = 'sms')
	{
		if (method_exists($this->gateway, 'startVerificationVia')) {
			return $this->gateway->startVerificationVia($phone_number, $channel);
		}

		return $this->gateway->startVerification($phone_number);
	}

	public function checkVerification($id, $code, $phone_number)
	{
		return $this->gateway->checkVerification($id, $code, $phone_number);
	}

	public function sendSms($phone_number, $messageBody)
	{
		return $this->gateway->sendSms($phone_number, $messageBody);
	}

	public function getVerificationData(string $id): ?array
	{
		return method_exists($this->gateway, 'getVerificationData')
			? $this->gateway->getVerificationData($id)
			: null;
	}
}
