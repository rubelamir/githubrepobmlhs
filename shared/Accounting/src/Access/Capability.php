<?php

declare(strict_types=1);

namespace School\Accounting\Access;

/**
 * Single source of truth for what each accounting role may do.
 * Every page and POST action must check one of these server-side.
 */
final class Capability
{
    public const DASHBOARD_VIEW = 'dashboard.view';
    public const OFFICERS_MANAGE = 'officers.manage';
    public const FEE_STRUCTURE_MANAGE = 'fee_structure.manage';
    public const CHART_MANAGE = 'chart_of_accounts.manage';
    public const FEES_VIEW_ALL = 'fees.view_all';
    public const FEES_VIEW_CLASS = 'fees.view_class';
    public const FEES_COLLECT = 'fees.collect';
    public const CONCESSION_REQUEST = 'concession.request';
    public const CONCESSION_APPROVE = 'concession.approve';
    public const INVOICE_MANAGE = 'invoices.manage';
    public const INVOICE_CANCEL = 'invoices.cancel';
    public const WAIVER_REQUEST = 'waiver.request';
    public const WAIVER_APPROVE = 'waiver.approve';
    public const HANDOVER_SUBMIT = 'handover.submit';
    public const HANDOVER_RECEIVE = 'handover.receive';
    public const VOUCHER_CREATE = 'voucher.create';
    public const VOUCHER_APPROVE = 'voucher.approve';
    public const BANK_RECONCILE = 'bank.reconcile';
    public const GATEWAY_SETTINGS = 'gateway.settings';
    public const REFUND_REQUEST = 'refund.request';
    public const REFUND_APPROVE = 'refund.approve';
    public const REPORTS_VIEW_ALL = 'reports.view_all';
    public const REPORTS_VIEW_CLASS = 'reports.view_class';
    public const PERIOD_CLOSE = 'period.close';
    public const AUDIT_VIEW = 'audit.view';

    /**
     * Capabilities that a class teacher holds only for the classes/sections
     * assigned to them. Head and accounts officer hold them school-wide.
     */
    public const CLASS_SCOPED = [
        self::FEES_VIEW_CLASS,
        self::FEES_COLLECT,
        self::CONCESSION_REQUEST,
        self::WAIVER_REQUEST,
        self::REPORTS_VIEW_CLASS,
    ];

    public const MATRIX = [
        Role::HEAD => [
            self::DASHBOARD_VIEW,
            self::OFFICERS_MANAGE,
            self::FEE_STRUCTURE_MANAGE,
            self::CHART_MANAGE,
            self::FEES_VIEW_ALL,
            self::FEES_VIEW_CLASS,
            self::FEES_COLLECT,
            self::CONCESSION_REQUEST,
            self::CONCESSION_APPROVE,
            self::INVOICE_MANAGE,
            self::INVOICE_CANCEL,
            self::WAIVER_REQUEST,
            self::WAIVER_APPROVE,
            self::HANDOVER_RECEIVE,
            self::VOUCHER_CREATE,
            self::VOUCHER_APPROVE,
            self::BANK_RECONCILE,
            self::GATEWAY_SETTINGS,
            self::REFUND_REQUEST,
            self::REFUND_APPROVE,
            self::REPORTS_VIEW_ALL,
            self::REPORTS_VIEW_CLASS,
            self::PERIOD_CLOSE,
            self::AUDIT_VIEW,
        ],
        Role::OFFICER => [
            self::DASHBOARD_VIEW,
            self::FEES_VIEW_ALL,
            self::FEES_VIEW_CLASS,
            self::FEES_COLLECT,
            self::CONCESSION_REQUEST,
            self::INVOICE_MANAGE,
            self::WAIVER_REQUEST,
            self::HANDOVER_RECEIVE,
            self::VOUCHER_CREATE,
            self::BANK_RECONCILE,
            self::REFUND_REQUEST,
            self::REPORTS_VIEW_ALL,
            self::REPORTS_VIEW_CLASS,
        ],
        Role::CLASS_TEACHER => [
            self::DASHBOARD_VIEW,
            self::FEES_VIEW_CLASS,
            self::FEES_COLLECT,
            self::CONCESSION_REQUEST,
            self::WAIVER_REQUEST,
            self::HANDOVER_SUBMIT,
            self::REPORTS_VIEW_CLASS,
        ],
    ];

    private const LABELS = [
        self::DASHBOARD_VIEW => 'হিসাব ড্যাশবোর্ড দেখা',
        self::OFFICERS_MANAGE => 'অ্যাকাউন্টস অফিসার নিয়োগ/প্রত্যাহার',
        self::FEE_STRUCTURE_MANAGE => 'ফি কাঠামো নির্ধারণ',
        self::CHART_MANAGE => 'চার্ট অব অ্যাকাউন্টস নিয়ন্ত্রণ',
        self::FEES_VIEW_ALL => 'সব শিক্ষার্থীর ফি ও বকেয়া দেখা',
        self::FEES_VIEW_CLASS => 'নিজ শ্রেণীর ফি ও বকেয়া দেখা',
        self::FEES_COLLECT => 'ফি আদায় ও রসিদ প্রদান',
        self::CONCESSION_REQUEST => 'ফি-শ্রেণী (বৃত্তি/উপবৃত্তি) ও ব্যক্তিগত ছাড়ের আবেদন',
        self::CONCESSION_APPROVE => 'ফি-শ্রেণী ও ব্যক্তিগত ছাড় অনুমোদন',
        self::INVOICE_MANAGE => 'ইনভয়েস তৈরি ও বিলম্ব জরিমানা প্রয়োগ',
        self::INVOICE_CANCEL => 'ইনভয়েস বাতিল',
        self::WAIVER_REQUEST => 'ছাড়/মওকুফের আবেদন',
        self::WAIVER_APPROVE => 'ছাড়/মওকুফ অনুমোদন',
        self::HANDOVER_SUBMIT => 'আদায়কৃত টাকা অফিসারের কাছে হস্তান্তর',
        self::HANDOVER_RECEIVE => 'শিক্ষকদের হস্তান্তর গ্রহণ',
        self::VOUCHER_CREATE => 'আয়-ব্যয় ভাউচার তৈরি',
        self::VOUCHER_APPROVE => 'ভাউচার ও রিভার্সাল অনুমোদন',
        self::BANK_RECONCILE => 'ব্যাংক জমা যাচাই ও রিকনসিলিয়েশন',
        self::GATEWAY_SETTINGS => 'পেমেন্ট গেটওয়ে সেটিংস',
        self::REFUND_REQUEST => 'রিফান্ডের আবেদন',
        self::REFUND_APPROVE => 'রিফান্ড অনুমোদন',
        self::REPORTS_VIEW_ALL => 'সব আর্থিক রিপোর্ট',
        self::REPORTS_VIEW_CLASS => 'নিজ শ্রেণীর আদায় ও বকেয়া রিপোর্ট',
        self::PERIOD_CLOSE => 'মাস সমাপনী ও পুনরায় খোলা',
        self::AUDIT_VIEW => 'হিসাব অডিট লগ দেখা',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public static function forRoles(array $roles): array
    {
        $granted = [];
        foreach ($roles as $role) {
            foreach (self::MATRIX[$role] ?? [] as $capability) {
                $granted[$capability] = true;
            }
        }

        return array_keys($granted);
    }

    public static function isClassScoped(string $capability): bool
    {
        return in_array($capability, self::CLASS_SCOPED, true);
    }

    public static function label(string $capability): string
    {
        return self::LABELS[$capability] ?? $capability;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return self::LABELS;
    }
}
