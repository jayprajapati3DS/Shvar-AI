<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\LeadStatus;
use App\Enums\Priority;
use App\Models\Company;
use App\Models\Lead;
use Illuminate\Database\Seeder;

/**
 * The five validation scenarios from the Phase 3 brief.
 *
 *     php artisan db:seed --class=RecommendationScenarioSeeder
 *
 * Deliberately NOT part of the default seed. These exist to exercise the
 * recommendation engine against known-shaped companies, including the important
 * negative case: a company with no medical connection at all, where the correct
 * behaviour is a weak or empty recommendation rather than a forced match.
 *
 * All companies are invented and use .example domains (RFC 2606, cannot
 * resolve). Remove with: php artisan migrate:fresh --seed
 */
class RecommendationScenarioSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->scenarios() as $scenario) {
            $company = Company::updateOrCreate(
                ['name' => $scenario['company']['name']],
                $scenario['company'],
            );

            Lead::updateOrCreate(
                ['company_id' => $company->id, 'email' => $scenario['contact']['email']],
                [
                    ...$scenario['contact'],
                    'company_id' => $company->id,
                    'lead_source' => $scenario['lead_source'],
                    'lead_status' => LeadStatus::Researching->value,
                    'priority' => Priority::Medium->value,
                    'notes' => $scenario['notes'] ?? null,
                ],
            );
        }
    }

    /**
     * Each entry documents what a correct analysis should produce, so the
     * expectation lives next to the fixture rather than only in a test file.
     *
     * @return list<array<string, mixed>>
     */
    private function scenarios(): array
    {
        return [
            // 1. Patient-specific implant manufacturer.
            //    Expect: 3dsurgical Platform + Pre-operative Planning strong;
            //            MySegmenter / Segmentation Services possible.
            [
                'company' => [
                    'name' => 'Craniofax Patient-Specific Implants',
                    'website' => 'https://craniofax.example',
                    'country' => 'Germany',
                    'city' => 'Freiburg',
                    'industry' => 'Medical Devices',
                    'company_type' => 'PSI Manufacturer',
                    'description' => 'Develops patient-specific cranial and mandibular implants for '
                        .'reconstructive surgery. Each case is designed individually from patient imaging '
                        .'and reviewed with the operating surgeon before manufacture.',
                    'specialties' => "Maxillofacial surgery\nCranial reconstruction\nMandibular reconstruction",
                    'products_services' => 'Patient-specific cranial plates, mandibular reconstruction plates, '
                        .'surgical guides.',
                ],
                'contact' => [
                    'first_name' => 'Katrin',
                    'last_name' => 'Vogel',
                    'job_title' => 'Head of Design Engineering',
                    'department' => 'R&D',
                    'email' => 'katrin.vogel@craniofax.example',
                    'country' => 'Germany',
                ],
                'lead_source' => 'Conference',
                'notes' => 'Design team of six. Surgeon review is currently done over email with '
                    .'screenshots, which they described as their biggest bottleneck.',
            ],

            // 2. AI medical imaging company.
            //    Expect: MySegmenter + Medical Segmentation Services strong.
            [
                'company' => [
                    'name' => 'Corvus Imaging AI',
                    'website' => 'https://corvus-ai.example',
                    'country' => 'United Kingdom',
                    'city' => 'Oxford',
                    'industry' => 'AI Company',
                    'company_type' => 'Software Vendor',
                    'description' => 'Develops AI algorithms for CT segmentation and automated '
                        .'measurement. Training their models requires large volumes of accurately '
                        .'segmented anatomical data.',
                    'specialties' => "Radiology\nComputer vision\nCT imaging",
                    'products_services' => 'Automated CT segmentation algorithms, quantitative imaging '
                        .'measurement tools.',
                ],
                'contact' => [
                    'first_name' => 'Rahul',
                    'last_name' => 'Menon',
                    'job_title' => 'Head of Machine Learning',
                    'department' => 'Engineering',
                    'email' => 'rahul.menon@corvus-ai.example',
                    'country' => 'United Kingdom',
                ],
                'lead_source' => 'Inbound Enquiry',
                'notes' => 'Asked specifically about turnaround time and per-case cost for labelled '
                    .'training data.',
            ],

            // 3. Medical university.
            //    Expect: Vision Anatomy VR + Vision Anatomy Table strong.
            [
                'company' => [
                    'name' => 'Westmoor Medical College',
                    'website' => 'https://westmoor-med.example',
                    'country' => 'India',
                    'city' => 'Pune',
                    'industry' => 'University',
                    'company_type' => 'Medical College',
                    'description' => 'Medical education institution with a large anatomy department '
                        .'teaching undergraduate and postgraduate cohorts. Cadaver availability limits '
                        .'dissection hours per student.',
                    'specialties' => "Anatomy\nMedical education",
                    'products_services' => 'Undergraduate and postgraduate medical degrees.',
                ],
                'contact' => [
                    'first_name' => 'Anjali',
                    'last_name' => 'Deshpande',
                    'job_title' => 'Professor and Head of Anatomy',
                    'department' => 'Anatomy',
                    'email' => 'anjali.deshpande@westmoor-med.example',
                    'country' => 'India',
                ],
                'lead_source' => 'Referral',
                'notes' => 'Capital budget cycle opens in the next quarter.',
            ],

            // 4. Orthopedic implant manufacturer (knee).
            //    Expect: 3dsurgical Platform, with the Knee Planning module
            //            specifically - the module must be justified, not guessed.
            [
                'company' => [
                    'name' => 'Ridgeline Orthopedics',
                    'website' => 'https://ridgeline-ortho.example',
                    'country' => 'United States',
                    'state' => 'Indiana',
                    'city' => 'Warsaw',
                    'industry' => 'Medical Devices',
                    'company_type' => 'Implant Company',
                    'description' => 'Develops total and partial knee replacement implants, including '
                        .'patient-matched cutting guides derived from pre-operative CT.',
                    'specialties' => "Orthopedics\nKnee arthroplasty",
                    'products_services' => 'Total knee systems, partial knee systems, patient-matched '
                        .'cutting guides.',
                ],
                'contact' => [
                    'first_name' => 'Marcus',
                    'last_name' => 'Feld',
                    'job_title' => 'Director of Product Development',
                    'department' => 'R&D',
                    'email' => 'marcus.feld@ridgeline-ortho.example',
                    'country' => 'United States',
                ],
                'lead_source' => 'Trade Show',
                'notes' => 'Evaluating whether to build planning software in-house or license it.',
            ],

            // 5. NEGATIVE CASE - no medical connection.
            //    Expect: no recommendation, or weak/low confidence. The engine
            //    must be willing to say nothing fits.
            [
                'company' => [
                    'name' => 'Trelawney Logistics Software',
                    'website' => 'https://trelawney-logistics.example',
                    'country' => 'Ireland',
                    'city' => 'Cork',
                    'industry' => 'Software',
                    'company_type' => 'Software Vendor',
                    'description' => 'Builds fleet management and route optimisation software for '
                        .'commercial haulage operators. No healthcare or medical activity.',
                    'specialties' => "Logistics\nRoute optimisation",
                    'products_services' => 'Fleet tracking, driver scheduling, route optimisation.',
                ],
                'contact' => [
                    'first_name' => 'Declan',
                    'last_name' => 'Byrne',
                    'job_title' => 'Chief Technology Officer',
                    'department' => 'Engineering',
                    'email' => 'declan.byrne@trelawney-logistics.example',
                    'country' => 'Ireland',
                ],
                'lead_source' => 'CSV Import',
                'notes' => 'Imported by mistake from a general software vendor list.',
            ],
        ];
    }
}
