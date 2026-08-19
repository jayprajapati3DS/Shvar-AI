<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The live product/service portfolio.
 *
 * Seeded with updateOrCreate keyed on `name`, so re-running `db:seed` refreshes
 * the catalogue without duplicating rows. Multi-value fields (target_customer,
 * key_features, target_specialty) are stored newline-separated - see
 * Product::toLines().
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->products() as $product) {
            Product::updateOrCreate(['name' => $product['name']], $product);
        }
    }

    /** @return list<array<string, mixed>> */
    private function products(): array
    {
        return [
            [
                'name' => '3dsurgical Platform',
                'category' => 'Medical Software / Surgical Planning',
                'short_description' => 'A digital medical platform for case management, 3D visualization, surgical planning and review workflows.',
                'detailed_description' => <<<'TXT'
                    A digital medical platform that carries a surgical case from intake through planning to
                    surgeon sign-off. It combines case and medical data management with a 3D viewer and
                    DICOM visualization, then layers anatomy-specific planning modules on top.

                    Patient-specific implants and instruments are reviewed inside the same platform, so the
                    design team and the operating surgeon work from one record rather than exchanging files.
                    The planning module set is extensible: further custom orthopedic and maxillofacial
                    workflows can be added for a specific customer.
                    TXT,
                'key_features' => <<<'TXT'
                    Case management
                    Medical data management
                    3D Viewer
                    DICOM visualization
                    Patient-specific implant/instrument review
                    Surgeon review and approval
                    Fibula Flap Planning
                    Orthognathic Planning
                    Hip Planning
                    Knee Planning
                    Shoulder Planning
                    Future custom orthopedic and maxillofacial modules
                    TXT,
                'target_customer' => <<<'TXT'
                    Medical device companies
                    Patient-specific implant manufacturers
                    PSI companies
                    Hospitals
                    Surgical planning companies
                    Orthopedic companies
                    Maxillofacial companies
                    Medical software companies
                    TXT,
                'target_specialty' => <<<'TXT'
                    Orthopedics
                    Maxillofacial surgery
                    Reconstructive surgery
                    Hip
                    Knee
                    Shoulder
                    TXT,
                'value_proposition' => 'One platform covering case management, 3D/DICOM visualization, anatomy-specific planning and surgeon approval, replacing scattered file exchange with a single reviewable record.',
                'sales_notes' => null,
                'active' => true,
            ],
            [
                'name' => 'MySegmenter',
                'category' => 'Medical Image Segmentation',
                'short_description' => 'Medical image segmentation software that converts DICOM medical imaging data into 3D anatomical data.',
                'detailed_description' => <<<'TXT'
                    Medical image segmentation software that turns DICOM imaging data into 3D anatomical
                    models. FDA 510(k) cleared.

                    Suited to teams that need segmentation capability in-house rather than as an outsourced
                    service: hospitals building their own 3D programmes, device companies preparing anatomy
                    for implant design, and AI or 3D printing companies producing training or manufacturing
                    data at volume.
                    TXT,
                'key_features' => <<<'TXT'
                    FDA 510(k) cleared
                    Medical image segmentation
                    DICOM to 3D anatomical model conversion
                    Supports conversion of medical imaging data into 3D anatomical models
                    TXT,
                'target_customer' => <<<'TXT'
                    Hospitals
                    Medical device companies
                    Implant companies
                    Surgical planning companies
                    AI companies
                    3D printing companies
                    Research organizations
                    TXT,
                'target_specialty' => <<<'TXT'
                    Radiology
                    Orthopedics
                    Maxillofacial surgery
                    Cardiovascular
                    Research and academia
                    TXT,
                'value_proposition' => 'FDA 510(k) cleared segmentation, bringing DICOM-to-3D capability in-house instead of outsourcing every case.',
                'sales_notes' => 'Lead with the 510(k) clearance - it is the differentiator against non-cleared segmentation tooling.',
                'active' => true,
            ],
            [
                'name' => 'Medical Segmentation Services',
                'category' => 'Professional Services',
                'short_description' => 'Segmentation service: send medical imaging cases, receive processed 3D anatomical models in STL or other required formats.',
                'detailed_description' => <<<'TXT'
                    A managed medical image segmentation service. Customers submit medical imaging cases and
                    receive processed 3D anatomical models back in STL or whatever format their downstream
                    workflow needs.

                    The fit is companies with either high case volume or limited internal segmentation
                    resources: they get output without hiring and training a segmentation team.
                    TXT,
                'key_features' => <<<'TXT'
                    Case-by-case medical image segmentation
                    Output as STL or other required formats
                    Handles high case volume
                    No internal segmentation team required
                    TXT,
                'target_customer' => <<<'TXT'
                    Medical device companies
                    AI companies
                    3D printing companies
                    Surgical planning companies
                    Companies with high case volume
                    Companies with limited internal segmentation resources
                    TXT,
                'target_specialty' => <<<'TXT'
                    Orthopedics
                    Maxillofacial surgery
                    Cardiovascular
                    AI training data
                    3D printing
                    TXT,
                'value_proposition' => 'Segmentation capacity on demand - scale case volume up or down without building an internal team.',
                'sales_notes' => 'Natural fallback when a prospect wants MySegmenter but has no internal capacity to run it.',
                'active' => true,
            ],
            [
                'name' => 'Pre-operative Planning & 3D Design Services',
                'category' => 'Professional Services',
                'short_description' => 'Medical image processing, pre-operative planning, patient-specific planning and 3D medical design services.',
                'detailed_description' => <<<'TXT'
                    A services engagement covering medical image processing, pre-operative and
                    patient-specific planning, and 3D medical design.

                    Used by device and implant companies that need patient-specific planning and design
                    output without expanding their own engineering team, and by hospitals and surgeons who
                    want a plan prepared for a specific case.
                    TXT,
                'key_features' => <<<'TXT'
                    Medical image processing
                    Pre-operative planning
                    Patient-specific planning
                    3D medical design
                    TXT,
                'target_customer' => <<<'TXT'
                    Medical device companies
                    Hospitals
                    Surgeons
                    Implant companies
                    PSI companies
                    Surgical planning companies
                    TXT,
                'target_specialty' => <<<'TXT'
                    Orthopedics
                    Maxillofacial surgery
                    Reconstructive surgery
                    Trauma
                    TXT,
                'value_proposition' => 'Patient-specific planning and 3D design delivered as a service - engineering output without the headcount.',
                'sales_notes' => 'Often pairs with the 3dsurgical Platform: services first, platform once volume justifies it.',
                'active' => true,
            ],
            [
                'name' => 'Vision Anatomy VR',
                'category' => 'Medical Education',
                'short_description' => 'VR-based anatomy education platform with multiple anatomical systems and layers.',
                'detailed_description' => <<<'TXT'
                    A VR anatomy education and learning platform. Students move through multiple anatomical
                    systems and peel through layers to understand structure and spatial relationships in a
                    way flat atlases cannot show.

                    Aimed at anatomy departments and medical education institutions building or modernising
                    their teaching setup.
                    TXT,
                'key_features' => <<<'TXT'
                    VR-based anatomy learning
                    Multiple anatomical systems
                    Layer-by-layer anatomical exploration
                    Built for teaching and self-study
                    TXT,
                'target_customer' => <<<'TXT'
                    Medical colleges
                    Universities
                    Anatomy departments
                    Medical education institutions
                    Training centers
                    TXT,
                'target_specialty' => <<<'TXT'
                    Anatomy
                    Medical education
                    Nursing education
                    Allied health education
                    TXT,
                'value_proposition' => 'Layered VR anatomy that scales teaching beyond cadaver availability.',
                'sales_notes' => 'Frequently sold alongside Vision Anatomy Table to the same anatomy department budget.',
                'active' => true,
            ],
            [
                'name' => 'Vision Anatomy Table',
                'category' => 'Medical Education',
                'short_description' => 'Virtual dissection and anatomy visualization table for medical education and training. Available in 55 inch and 80 inch.',
                'detailed_description' => <<<'TXT'
                    A virtual dissection table for anatomy teaching and training: a large touch display
                    where a class works through anatomy together, as a shared-screen complement to
                    headset-based learning.

                    Available in two display sizes: 55 inch and 80 inch.
                    TXT,
                'key_features' => <<<'TXT'
                    Virtual dissection
                    Anatomy visualization
                    Group teaching and training use
                    Available in 55 inch
                    Available in 80 inch
                    TXT,
                'target_customer' => <<<'TXT'
                    Medical colleges
                    Universities
                    Anatomy departments
                    Medical education institutions
                    Training centers
                    TXT,
                'target_specialty' => <<<'TXT'
                    Anatomy
                    Medical education
                    Nursing education
                    Allied health education
                    TXT,
                'value_proposition' => 'A shared virtual dissection surface for group anatomy teaching, in 55 inch and 80 inch formats.',
                'sales_notes' => 'Confirm room size and mounting before quoting the 80 inch.',
                'active' => true,
            ],
            [
                'name' => 'Custom Medical Software Development',
                'category' => 'Custom Software Development',
                'short_description' => 'Custom development of medical and healthcare software across web, desktop, mobile, imaging, AR and VR.',
                'detailed_description' => <<<'TXT'
                    Custom development of medical and healthcare software, from a standalone tool to
                    modernising a legacy application.

                    Scope spans web, desktop, Android and iOS applications; medical imaging and 3D
                    visualization; surgical planning; AR and VR applications; medical simulation; and
                    education and training software.
                    TXT,
                'key_features' => <<<'TXT'
                    Web applications
                    Desktop applications
                    Android applications
                    iOS applications
                    Medical imaging applications
                    3D visualization
                    Surgical planning
                    AR applications
                    VR applications
                    Medical simulation
                    Education and training software
                    Legacy software modernization
                    TXT,
                'target_customer' => <<<'TXT'
                    Medical device companies
                    Healthcare companies
                    Medical software companies
                    Implant manufacturers
                    Hospitals
                    Universities
                    Startups
                    AI companies
                    TXT,
                'target_specialty' => <<<'TXT'
                    Medical imaging
                    Surgical planning
                    Medical education
                    Healthcare IT
                    TXT,
                'value_proposition' => 'A medical-domain development team that already understands DICOM, 3D and surgical workflows - no ramp-up on the clinical context.',
                'sales_notes' => 'Good entry point when no packaged product fits the prospect exactly.',
                'active' => true,
            ],
        ];
    }
}
