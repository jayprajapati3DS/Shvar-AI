<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Optional demo data, for trying the app out before entering anything real.
 *
 *     php artisan db:seed --class=SampleDataSeeder
 *
 * NOT part of the default seed - DatabaseSeeder only loads the product
 * portfolio, because companies/contacts/leads should be your own data. The
 * companies below are invented and use .example domains, which are reserved by
 * RFC 2606 and can never resolve.
 *
 * To clear it again: php artisan migrate:fresh --seed
 */
class SampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::pluck('id', 'name');

        if ($products->isEmpty()) {
            $this->call(ProductSeeder::class);
            $products = Product::pluck('id', 'name');
        }

        foreach ($this->companies() as $definition) {
            $company = Company::updateOrCreate(
                ['name' => $definition['name']],
                collect($definition)->except(['contacts', 'lead'])->all(),
            );

            $contacts = collect($definition['contacts'])->map(
                fn (array $contact) => Contact::updateOrCreate(
                    ['email' => $contact['email']],
                    [...$contact, 'company_id' => $company->id],
                )
            );

            $leadDefinition = $definition['lead'];

            $lead = Lead::updateOrCreate(
                ['company_id' => $company->id, 'contact_id' => $contacts->first()->id],
                [
                    'lead_source' => $leadDefinition['source'],
                    'lead_status' => $leadDefinition['status'],
                    'priority' => $leadDefinition['priority'],
                    'notes' => $leadDefinition['notes'],
                ],
            );

            // Attach the products a human would plausibly have picked.
            foreach ($leadDefinition['products'] as $name => $reason) {
                if (! $products->has($name)) {
                    continue;
                }

                $lead->productMatches()->updateOrCreate(
                    ['product_id' => $products[$name]],
                    ['reason' => $reason],
                );
            }

            if ($lead->activities()->count() === 0) {
                $lead->activities()->create([
                    'type' => ActivityType::Note,
                    'title' => $leadDefinition['activity'],
                    'occurred_at' => now()->subDays(random_int(1, 20)),
                ]);
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function companies(): array
    {
        return [
            [
                'name' => 'Northfield Orthopedic Devices',
                'website' => 'https://northfield-ortho.example',
                'country' => 'United States',
                'state' => 'Minnesota',
                'city' => 'Minneapolis',
                'industry' => 'Medical Devices',
                'company_type' => 'PSI Manufacturer',
                'description' => 'Designs patient-specific knee and hip instrumentation.',
                'specialties' => "Knee\nHip",
                'products_services' => 'Patient-specific cutting guides, revision instrumentation.',
                'contacts' => [
                    [
                        'first_name' => 'Dana',
                        'last_name' => 'Whitfield',
                        'job_title' => 'Director of Engineering',
                        'department' => 'R&D',
                        'email' => 'dana.whitfield@northfield-ortho.example',
                        'phone' => '+1 612 555 0142',
                        'country' => 'United States',
                        'city' => 'Minneapolis',
                    ],
                ],
                'lead' => [
                    'source' => 'LinkedIn',
                    'status' => LeadStatus::Qualified->value,
                    'priority' => Priority::High->value,
                    'notes' => 'Outsources segmentation today and wants it in-house. Volume around 40 cases/month.',
                    'activity' => 'Intro call — confirmed segmentation is their bottleneck.',
                    'products' => [
                        'MySegmenter' => 'Wants FDA-cleared segmentation running in-house.',
                        '3dsurgical Platform' => 'Needs surgeon review and approval on knee/hip plans.',
                    ],
                ],
            ],
            [
                'name' => 'Sundaram Institute of Medical Sciences',
                'website' => 'https://sims.example',
                'country' => 'India',
                'state' => 'Tamil Nadu',
                'city' => 'Chennai',
                'industry' => 'University',
                'company_type' => 'Medical College',
                'description' => 'Teaching hospital with a large anatomy department.',
                'specialties' => "Anatomy\nMedical education",
                'products_services' => 'Undergraduate and postgraduate medical education.',
                'contacts' => [
                    [
                        'first_name' => 'Meera',
                        'last_name' => 'Raghavan',
                        'job_title' => 'Head of Anatomy',
                        'department' => 'Anatomy',
                        'email' => 'meera.raghavan@sims.example',
                        'phone' => '+91 44 5550 8821',
                        'country' => 'India',
                        'city' => 'Chennai',
                    ],
                ],
                'lead' => [
                    'source' => 'Conference',
                    'status' => LeadStatus::Proposal->value,
                    'priority' => Priority::High->value,
                    'notes' => 'Budget approved for this academic year. Room measured for the 80 inch.',
                    'activity' => 'Demo delivered to the anatomy faculty — strong interest in the table.',
                    'products' => [
                        'Vision Anatomy Table' => 'Group teaching for large first-year cohorts.',
                        'Vision Anatomy VR' => 'Self-study to complement the shared table.',
                    ],
                ],
            ],
            [
                'name' => 'Meridian AI Health',
                'website' => 'https://meridian-ai.example',
                'country' => 'United Kingdom',
                'state' => 'England',
                'city' => 'Cambridge',
                'industry' => 'AI Company',
                'company_type' => 'Software Vendor',
                'description' => 'Builds imaging models and needs large volumes of labelled anatomy.',
                'specialties' => "Radiology\nMachine learning",
                'products_services' => 'Automated radiology triage.',
                'contacts' => [
                    [
                        'first_name' => 'Tomas',
                        'last_name' => 'Beckett',
                        'job_title' => 'Head of Data',
                        'department' => 'Engineering',
                        'email' => 'tomas.beckett@meridian-ai.example',
                        'country' => 'United Kingdom',
                        'city' => 'Cambridge',
                    ],
                ],
                'lead' => [
                    'source' => 'Inbound Enquiry',
                    'status' => LeadStatus::Contacted->value,
                    'priority' => Priority::Medium->value,
                    'notes' => 'Needs several hundred segmented cases as training data. Price-sensitive per case.',
                    'activity' => 'Sent an overview of the segmentation service and turnaround times.',
                    'products' => [
                        'Medical Segmentation Services' => 'High case volume, no internal segmentation team.',
                    ],
                ],
            ],
            [
                'name' => 'Alpine Maxillofacial Solutions',
                'website' => 'https://alpine-mfs.example',
                'country' => 'Switzerland',
                'city' => 'Bern',
                'industry' => 'Medical Devices',
                'company_type' => 'Implant Company',
                'description' => 'Custom maxillofacial implants and reconstruction plates.',
                'specialties' => "Maxillofacial surgery\nReconstructive surgery",
                'products_services' => 'Patient-specific titanium implants.',
                'contacts' => [
                    [
                        'first_name' => 'Léa',
                        'last_name' => 'Bianchi',
                        'job_title' => 'Clinical Operations Lead',
                        'department' => 'Clinical',
                        'email' => 'lea.bianchi@alpine-mfs.example',
                        'country' => 'Switzerland',
                        'city' => 'Bern',
                    ],
                ],
                'lead' => [
                    'source' => 'Referral',
                    'status' => LeadStatus::Interested->value,
                    'priority' => Priority::Medium->value,
                    'notes' => 'Wants fibula flap and orthognathic planning delivered as a service first.',
                    'activity' => 'Referred by an existing customer. Asked for two sample cases.',
                    'products' => [
                        'Pre-operative Planning & 3D Design Services' => 'Needs planning output without adding engineers.',
                        '3dsurgical Platform' => 'Fibula flap and orthognathic modules match their caseload.',
                    ],
                ],
            ],
            [
                'name' => 'Harbour Point Hospital Group',
                'website' => 'https://harbourpoint.example',
                'country' => 'Australia',
                'state' => 'New South Wales',
                'city' => 'Sydney',
                'industry' => 'Hospital',
                'company_type' => 'Hospital Network',
                'description' => 'Four-site hospital group starting a 3D printing programme.',
                'specialties' => "Orthopedics\nTrauma",
                'products_services' => 'Acute and elective surgical care.',
                'contacts' => [
                    [
                        'first_name' => 'Nils',
                        'last_name' => 'Anderson',
                        'job_title' => 'Innovation Manager',
                        'department' => 'Clinical Innovation',
                        'email' => 'nils.anderson@harbourpoint.example',
                        'country' => 'Australia',
                        'city' => 'Sydney',
                    ],
                    [
                        'first_name' => 'Priya',
                        'last_name' => 'Nair',
                        'job_title' => 'Procurement Officer',
                        'department' => 'Procurement',
                        'email' => 'priya.nair@harbourpoint.example',
                        'country' => 'Australia',
                        'city' => 'Sydney',
                    ],
                ],
                'lead' => [
                    'source' => 'Trade Show',
                    'status' => LeadStatus::Researching->value,
                    'priority' => Priority::Low->value,
                    'notes' => 'Early stage. Establishing an in-house point-of-care 3D lab over the next year.',
                    'activity' => 'Met at a trade show. Sending information ahead of a follow-up next quarter.',
                    'products' => [
                        'MySegmenter' => 'Foundation for an in-house 3D lab.',
                    ],
                ],
            ],
            [
                'name' => 'Kestrel Surgical Software',
                'website' => 'https://kestrel-surgical.example',
                'country' => 'Netherlands',
                'city' => 'Eindhoven',
                'industry' => 'Medical Software',
                'company_type' => 'Software Vendor',
                'description' => 'Legacy surgical planning desktop product needing modernisation.',
                'specialties' => 'Surgical planning',
                'products_services' => 'Windows-only planning suite from 2011.',
                'contacts' => [
                    [
                        'first_name' => 'Sanne',
                        'last_name' => 'de Vries',
                        'job_title' => 'CTO',
                        'department' => 'Engineering',
                        'email' => 'sanne.devries@kestrel-surgical.example',
                        'country' => 'Netherlands',
                        'city' => 'Eindhoven',
                    ],
                ],
                'lead' => [
                    'source' => 'Partner',
                    'status' => LeadStatus::New->value,
                    'priority' => Priority::Medium->value,
                    'notes' => 'Wants to move the desktop product to web. No timeline yet.',
                    'activity' => 'Introduced through a mutual partner. No call scheduled yet.',
                    'products' => [
                        'Custom Medical Software Development' => 'Legacy modernisation to a web application.',
                    ],
                ],
            ],
            [
                'name' => 'Copper Basin Medical Printing',
                'website' => 'https://copperbasin3d.example',
                'country' => 'Canada',
                'state' => 'Ontario',
                'city' => 'Toronto',
                'industry' => '3D Printing',
                'company_type' => 'Service Bureau',
                'description' => 'Prints anatomical models for surgical teams.',
                'specialties' => "3D printing\nAnatomical models",
                'products_services' => 'Anatomical model printing, sterilisable guides.',
                'contacts' => [
                    [
                        'first_name' => 'Marcus',
                        'last_name' => 'Oyelaran',
                        'job_title' => 'Operations Director',
                        'department' => 'Operations',
                        'email' => 'marcus.oyelaran@copperbasin3d.example',
                        'country' => 'Canada',
                        'city' => 'Toronto',
                    ],
                ],
                'lead' => [
                    'source' => 'Existing Customer',
                    'status' => LeadStatus::Won->value,
                    'priority' => Priority::Low->value,
                    'notes' => 'Signed for segmentation services. Watch for platform upsell next year.',
                    'activity' => 'Contract signed for ongoing segmentation work.',
                    'products' => [
                        'Medical Segmentation Services' => 'Steady monthly case flow to convert for printing.',
                    ],
                ],
            ],
        ];
    }
}
