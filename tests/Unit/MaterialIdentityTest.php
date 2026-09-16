<?php

namespace Tests\Unit;

use App\Services\Meetstaat\MaterialIdentity;
use Tests\TestCase;

class MaterialIdentityTest extends TestCase
{
    public function test_extracts_rf_and_coating_product_codes(): void
    {
        $identity = new MaterialIdentity;

        $this->assertSame(['7133080'], $identity->productCodes('Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels'));
        $this->assertSame(['7133070'], $identity->productCodes('Ege Refor Heritage kamerbreed RF 7133070, Tapijt'));
        $this->assertSame(['f2.10.60'], $identity->productCodes('PU gietvloer Sikkens F2.10.60, Coating'));
    }

    public function test_shares_product_code_across_short_and_full_labels(): void
    {
        $identity = new MaterialIdentity;

        $this->assertTrue($identity->sharesProductCode(
            'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
            '7133080 Tapijttegels'
        ));
        $this->assertFalse($identity->sharesProductCode(
            'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
            'Ege Reform Heritage RF 7133290 (96 x 96 cm), Tapijttegels'
        ));
    }

    public function test_resolves_generic_type_only_when_unique(): void
    {
        $identity = new MaterialIdentity;
        $candidates = [
            'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
            'Ege Refor Heritage kamerbreed RF 7133070, Tapijt (0.01 cm x 0.01 cm)',
        ];

        $this->assertSame(
            'Ege Refor Heritage kamerbreed RF 7133070, Tapijt (0.01 cm x 0.01 cm)',
            $identity->resolveUniqueCanonical('Tapijt', $candidates)
        );
        $this->assertNull($identity->resolveUniqueCanonical('Tapijttegels', [
            'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
            'Ege Reform Heritage RF 7133290 (96 x 96 cm), Tapijttegels',
        ]));
    }

    public function test_extracts_dotted_product_code_and_links_entreemat_to_unique_coral_brush(): void
    {
        $identity = new MaterialIdentity;
        $canonical = '43.20.02 Coral Brush 5721-hurricane grey, Entreemat Banen';

        $this->assertContains('43.20.02', $identity->productCodes($canonical));
        $this->assertContains('5721', $identity->productCodes($canonical));
        $this->assertSame($canonical, $identity->resolveUniqueCanonical('Entreemat', [$canonical]));
        $this->assertNull($identity->resolveUniqueCanonical('Entreemat', [
            $canonical,
            '43.20.01 Coral Welcome 3202 desperado, Entreemat Banen',
        ]));
    }

    public function test_links_truncated_english_oak_legend_to_unique_canonical_variant(): void
    {
        $identity = new MaterialIdentity;
        $canonical = 'Tarkett pvc Classics-English Oak grege 55, PVC - LVT';
        $candidates = [
            $canonical,
            'Tarkett vinyl iQ Natural-dark warm grey, PVC / Vinyl',
            'Tarkett safe.t Granit Dark Sand 0508, PVC / Vinyl',
        ];

        $this->assertSame(
            $canonical,
            $identity->resolveUniqueCanonical('Tarkett pvc Classics-English Oak ...', $candidates)
        );
        $this->assertSame(
            $canonical,
            $identity->resolveUniqueCanonical('Tarkett pvc Classics-English Oak grege PVC Tarkett PVC', $candidates)
        );
        $this->assertTrue($identity->sharesIdentity(
            'Tarkett pvc Classics-English Oak grege PVC Tarkett PVC',
            $canonical
        ));
        $this->assertFalse($identity->sharesIdentity('Tarkett pvc', $canonical));
        $this->assertFalse($identity->sharesIdentity(
            'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels',
            'Ege Reform Heritage RF 7133290 (96 x 96 cm), Tapijttegels'
        ));
        $this->assertFalse($identity->sharesIdentity(
            'Tarkett vinyl iQ Natural-pink clay, PVC / Vinyl',
            'Tarkett vinyl iQ Natural-dark warm grey, PVC / Vinyl'
        ));
        $this->assertFalse($identity->sharesIdentity(
            'PU gietvloer , Ral 7039 met vlok, Coating',
            'PU gietvloer antislip, Ral 7039 met vlok, Coating'
        ));
        $this->assertFalse($identity->sharesIdentity(
            'PU gietvloer, Ral 7039 met vlok, Coating',
            'vloercoating op CD vloer, Coating'
        ));
        $this->assertFalse($identity->sharesIdentity(
            'PU gietvloer in RAL 7039 (eventueel voorzien va inkoop Amipox)',
            'Lijvige Epoxy vloercoating inkoop Amipox'
        ));
        $this->assertFalse($identity->sharesIdentity(
            'Tarkett safe.t Granit Dark Sand 0508, PVC / Vinyl',
            'Tarkett safe.t Granit light, PVC / Vinyl'
        ));
    }

    public function test_same_product_code_with_cork_backing_is_a_separate_variant(): void
    {
        $identity = new MaterialIdentity;
        $plain = 'Lino Art Urban R893-0555, flashy street grey, Linoleum';
        $cork = 'Lino Art Urban R893-0555 op kurk, flashy street grey, Linoleum';
        $candidates = [$plain, $cork];

        $this->assertFalse($identity->sharesIdentity($plain, $cork));
        $this->assertFalse($identity->sameExecutionVariant($plain, $cork));
        $this->assertSame($plain, $identity->resolveUniqueCanonical($plain, $candidates));
        $this->assertSame($cork, $identity->resolveUniqueCanonical($cork, $candidates));
        $this->assertNull($identity->resolveUniqueCanonical('Lino Art Urban R893-0555', $candidates));
        $this->assertTrue($identity->sharesProductCode($plain, $cork));
        $this->assertTrue($identity->sharesIdentity(
            '7133080 Tapijttegels',
            'Ege Reform Heritage RF 7133080 (96 x 96 cm), Tapijttegels'
        ));
    }

    public function test_strong_product_header_accepts_ege_code_line(): void
    {
        $identity = new MaterialIdentity;

        $this->assertTrue($identity->looksLikeStrongProductHeader('Ege Reform Heritage RF 7133080 (96 x 96 cm),'));
        $this->assertTrue($identity->looksLikeStrongProductHeader('Lino Art Urban R893-0555 op kurk, flashy street'));
        $this->assertTrue($identity->looksLikeProductTypeContinuation('Tapijttegels'));
    }

    public function test_flooring_variant_code_keeps_wrapped_color_on_the_same_material(): void
    {
        $identity = new MaterialIdentity;
        $short = 'V.02 Zomer Gerflor Mipolam affinity 4424';
        $full = 'V.02 Zomer Gerflor Mipolam affinity 4424 Smoked Opal, PVC Banen / Vinyl';
        $other = 'V.04 Zomer Gerflor Mipolam affinity 4424 Cloudy Night, PVC Banen / Vinyl';

        $this->assertSame(['v.02'], $identity->flooringVariantCodes($full));
        $this->assertTrue($identity->sharesFlooringVariantCode($short, $full));
        $this->assertTrue($identity->sharesIdentity($short, $full));
        $this->assertTrue($identity->sameExecutionVariant($short, $full));
        $this->assertSame($full, $identity->resolveUniqueCanonical($short, [$full, $other]));
        $this->assertFalse($identity->sharesIdentity($full, $other));
        $this->assertFalse($identity->sharesFlooringVariantCode($full, $other));
    }

    public function test_same_work_code_keeps_sp_and_hp_as_separate_executions(): void
    {
        $identity = new MaterialIdentity;
        $sp = '43.20.03a Epoxy gietvloer (sp) S 3500-N, donkergrijs, Coating';
        $hp = '43.20.03a Epoxy gietvloer (hp) S 3500-N, donkergrijs, Coating';
        $ral = '43.20.05a PU gietvloer Ral 7023, Coating';
        $sp05 = '43.20.05a PU gietvloer (sp) S 3500-N, Coating';

        $this->assertTrue($identity->sharesWorkCode($sp, $hp));
        $this->assertFalse($identity->sameExecutionVariant($sp, $hp));
        $this->assertFalse($identity->sharesIdentity($sp, $hp));
        $this->assertSame('43.20.03a|sp|s3500n', $identity->executionKey($sp));
        $this->assertSame('43.20.03a|hp|s3500n', $identity->executionKey($hp));
        $this->assertSame($sp, $identity->resolveUniqueCanonical($sp, [$sp, $hp]));
        $this->assertSame($hp, $identity->resolveUniqueCanonical($hp, [$sp, $hp]));
        $this->assertNull($identity->resolveUniqueCanonical('43.20.03a Epoxy gietvloer S 3500-N', [$sp, $hp]));
        $this->assertFalse($identity->sameExecutionVariant($sp05, $ral));
        $this->assertFalse($identity->sharesIdentity($sp05, $ral));
    }

    public function test_same_work_code_does_not_identify_different_material_products(): void
    {
        $identity = new MaterialIdentity;
        $coral = '43.20.02 Coral Brush 5721-hurricane grey, Entreemat Banen';
        $coralShort = '43.20.02 Coral Brush 5721-hurricane grey, Entreemat';
        $tarkett = '43.20.02 Tarkett vinyl iQ Natural-black, PVC / Vinyl';
        $gietvloer = '43.20.02 PU gietvloer, Ral 7039 met vlok, Coating';

        $this->assertTrue($identity->sharesWorkCode($coral, $tarkett));
        $this->assertTrue($identity->sharesIdentity($coral, $coralShort));
        $this->assertFalse($identity->sharesIdentity($coral, $tarkett));
        $this->assertFalse($identity->sharesIdentity($coral, $gietvloer));
        $this->assertFalse($identity->sharesIdentity($tarkett, $gietvloer));
        $this->assertFalse($identity->sharesProductVariant($coral, $tarkett));
        $this->assertFalse($identity->sharesIdentity(
            'Gietvloeren, holplint, vloercoating en antislip',
            '43.20.03a Epoxy gietvloer (sp) S 3500-N, donkergrijs, Coating'
        ));
        $this->assertFalse($identity->sharesIdentity(
            '43.20.02 Coral Brush 5730, vulcan black,, Tapijttegels',
            '43.20.04 Desso Airmaster 9520, warmgrijs, Tapijttegels'
        ));
    }
}
