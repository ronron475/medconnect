<?php
/**
 * Demo alias for HealthComplaintDomainDetector (production shared class).
 * Kept so existing demo / probe call sites continue to work.
 */
final class NlpStep3DemoHealthComplaintDetector
{
    public const DOMAIN_HEALTH = HealthComplaintDomainDetector::DOMAIN_HEALTH;
    public const DOMAIN_MEDCONNECT = HealthComplaintDomainDetector::DOMAIN_MEDCONNECT;
    public const DOMAIN_OOS = HealthComplaintDomainDetector::DOMAIN_OOS;
    public const DOMAIN_UNCLEAR = HealthComplaintDomainDetector::DOMAIN_UNCLEAR;
    public const DOMAIN_GREETING = HealthComplaintDomainDetector::DOMAIN_GREETING;

    public const CONF_HIGH = HealthComplaintDomainDetector::CONF_HIGH;
    public const CONF_MEDIUM = HealthComplaintDomainDetector::CONF_MEDIUM;
    public const CONF_LOW = HealthComplaintDomainDetector::CONF_LOW;

    public const ROUTE_NLP = HealthComplaintDomainDetector::ROUTE_NLP;
    public const ROUTE_OOS = HealthComplaintDomainDetector::ROUTE_OOS;
    public const ROUTE_GEMINI = HealthComplaintDomainDetector::ROUTE_GEMINI;
    public const ROUTE_MEDCONNECT = HealthComplaintDomainDetector::ROUTE_MEDCONNECT;
    public const ROUTE_GREETING = HealthComplaintDomainDetector::ROUTE_GREETING;

    /** @return array<string, mixed> */
    public static function detect(string $text): array
    {
        return HealthComplaintDomainDetector::detect($text);
    }

    /** @param mixed $raw */
    public static function validateGeminiDomain($raw): ?string
    {
        return HealthComplaintDomainDetector::validateGeminiDomain($raw);
    }
}
