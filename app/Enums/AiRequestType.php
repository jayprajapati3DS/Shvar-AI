<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an AI request was for.
 *
 * Stored on ai_requests.request_type so the local log can be filtered by
 * purpose, and so Phase 3 features are already accounted for in the schema.
 *
 * PHASE 2 implements only `General` (the AI Playground). The other cases are
 * declared but nothing generates them yet - see implementedCases().
 */
enum AiRequestType: string
{
    case General = 'general';
    case CompanyAnalysis = 'company_analysis';
    case ProductRecommendation = 'product_recommendation';
    case EmailGeneration = 'email_generation';
    case FollowUpGeneration = 'follow_up_generation';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::CompanyAnalysis => 'Company Analysis',
            self::ProductRecommendation => 'Product Recommendation',
            self::EmailGeneration => 'Email Generation',
            self::FollowUpGeneration => 'Follow-up Generation',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::General => 'slate',
            self::CompanyAnalysis => 'sky',
            self::ProductRecommendation => 'indigo',
            self::EmailGeneration => 'violet',
            self::FollowUpGeneration => 'amber',
        };
    }

    /**
     * The types the application can actually produce today.
     *
     * General arrived in Phase 2 (the AI Playground), ProductRecommendation in
     * Phase 3, EmailGeneration in Phase 4. FollowUpGeneration is declared so the
     * schema and the UI already account for it, but nothing generates it yet.
     *
     * @return list<self>
     */
    public static function implementedCases(): array
    {
        return [self::General, self::ProductRecommendation, self::EmailGeneration];
    }

    public function isImplemented(): bool
    {
        return in_array($this, self::implementedCases(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string, color: string, implemented: bool}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'color' => $case->color(),
            'implemented' => $case->isImplemented(),
        ], self::cases());
    }
}
