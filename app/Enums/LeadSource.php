<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Suggested lead sources. Unlike the other enums this is NOT enforced on the
 * column — `lead_source` is a free-text field so you can type anything at a
 * trade show. These values just power the dropdown and the filter list.
 */
enum LeadSource: string
{
    case LinkedIn = 'LinkedIn';
    case Website = 'Website';
    case Referral = 'Referral';
    case Conference = 'Conference';
    case TradeShow = 'Trade Show';
    case ColdOutreach = 'Cold Outreach';
    case InboundEnquiry = 'Inbound Enquiry';
    case Partner = 'Partner';
    case ExistingCustomer = 'Existing Customer';
    case Import = 'CSV Import';
    case Other = 'Other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case) => [
            'value' => $case->value,
            'label' => $case->value,
        ], self::cases());
    }
}
