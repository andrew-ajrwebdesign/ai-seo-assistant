<?php
/**
 * Google_Reads — "What Google reads on this page" in plain words: the structured data found on the
 * rendered page, what the page type should add, and whether the page is linked to the business.
 *
 * Read off the scan's facts (Html_Parser `schema_nodes`); never written by Claude (decision 2026-10-06:
 * schema is never an editor job). Pure PHP, unit-tested.
 *
 * @package AJR\SEOAssistant
 */

declare( strict_types=1 );

namespace AJR\SEOAssistant\Scan;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-words structured data.
 */
class Google_Reads {

	/**
	 * Schema.org types that describe the business itself.
	 *
	 * @var array<int,string>
	 */
	public const BUSINESS_TYPES = [ 'LocalBusiness', 'Organization', 'Corporation', 'ProfessionalService', 'RealEstateAgent', 'Plumber', 'Electrician', 'HVACBusiness', 'RoofingContractor', 'GeneralContractor', 'HomeAndConstructionBusiness', 'Locksmith', 'MovingCompany', 'LegalService', 'Attorney', 'Notary', 'AccountingService', 'FinancialService', 'InsuranceAgency', 'Dentist', 'Physician', 'MedicalBusiness', 'MedicalClinic', 'HealthAndBeautyBusiness', 'BeautySalon', 'HairSalon', 'DaySpa', 'Restaurant', 'FoodEstablishment', 'CafeOrCoffeeShop', 'Bakery', 'Store', 'AutomotiveBusiness', 'AutoRepair', 'LodgingBusiness', 'Hotel', 'ChildCare', 'EducationalOrganization', 'EmploymentAgency', 'TravelAgency', 'SportsActivityLocation', 'NGO' ];

	/**
	 * The types each page type should add (any one of them).
	 *
	 * @var array<string,array<int,string>>
	 */
	public const EXPECTED = [
		'service'     => [ 'Service' ],
		'area'        => [ 'Place', 'City', 'AdministrativeArea', 'Service' ],
		'article'     => [ 'Article', 'BlogPosting', 'NewsArticle' ],
		'faq'         => [ 'FAQPage' ],
		'contact'     => [ 'ContactPage' ],
		'team_member' => [ 'ProfilePage', 'Person' ],
	];

	/**
	 * One node in plain words ('' to leave it out).
	 *
	 * @param string $type Schema.org type.
	 * @param string $name Its name.
	 */
	public static function words( string $type, string $name = '' ): string {
		$named = static fn( string $label ): string => '' !== $name ? $label . ': ' . $name : $label;
		if ( in_array( $type, self::BUSINESS_TYPES, true ) ) {
			return __( 'Business', 'ai-seo-assistant' );
		}
		switch ( $type ) {
			case 'BreadcrumbList':
				return __( 'Breadcrumb', 'ai-seo-assistant' );
			case 'WebPage':
			case 'ItemPage':
			case 'CollectionPage':
			case 'AboutPage':
				return __( 'Web page', 'ai-seo-assistant' );
			case 'WebSite':
				return __( 'Website', 'ai-seo-assistant' );
			case 'Service':
				return $named( __( 'Service', 'ai-seo-assistant' ) );
			case 'FAQPage':
				return __( 'Questions and answers', 'ai-seo-assistant' );
			case 'Article':
			case 'BlogPosting':
			case 'NewsArticle':
				return __( 'Article', 'ai-seo-assistant' );
			case 'ContactPage':
				return __( 'Contact page', 'ai-seo-assistant' );
			case 'ProfilePage':
				return __( 'Profile page', 'ai-seo-assistant' );
			case 'Person':
				return $named( __( 'Person', 'ai-seo-assistant' ) );
			case 'Place':
			case 'City':
			case 'AdministrativeArea':
				return $named( __( 'Place', 'ai-seo-assistant' ) );
			case 'ImageObject':
			case 'SearchAction':
			case 'ReadAction':
			case 'ListItem':
			case 'EntryPoint':
			case 'PropertyValueSpecification':
			case 'PostalAddress':
			case 'GeoCoordinates':
			case 'OpeningHoursSpecification':
			case 'ContactPoint':
			case 'Offer':
			case 'Rating':
			case 'Language':
				return ''; // Parts of another node, not something Google shows for the page.
		}

		return trim( (string) preg_replace( '/(?<!^)([A-Z])/', ' $1', $type ) );
	}

	/**
	 * What Google can read now: unique plain-words chips.
	 *
	 * @param array<int,array{type:string,name:string,id:string}> $nodes Html_Parser schema_nodes.
	 * @return array<int,string>
	 */
	public static function chips( array $nodes ): array {
		$out = [];
		foreach ( $nodes as $n ) {
			$w = self::words( (string) ( $n['type'] ?? '' ), (string) ( $n['name'] ?? '' ) );
			if ( '' !== $w ) {
				$out[] = $w;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * What the page type should add and the page does not print yet ('' when nothing is missing).
	 *
	 * @param string                                              $type  Page type.
	 * @param array<int,array{type:string,name:string,id:string}> $nodes Nodes.
	 */
	public static function missing( string $type, array $nodes ): string {
		$want = self::EXPECTED[ $type ] ?? [];
		if ( [] === $want ) {
			return '';
		}
		foreach ( $nodes as $n ) {
			if ( in_array( (string) ( $n['type'] ?? '' ), $want, true ) ) {
				return '';
			}
		}

		return self::words( $want[0] );
	}

	/**
	 * Whether the page prints the business node.
	 *
	 * @param array<int,array{type:string,name:string,id:string}> $nodes Nodes.
	 */
	public static function has_business( array $nodes ): bool {
		foreach ( $nodes as $n ) {
			if ( in_array( (string) ( $n['type'] ?? '' ), self::BUSINESS_TYPES, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Google's Rich Results Test for a URL.
	 *
	 * @param string $url Page URL.
	 */
	public static function rich_results_url( string $url ): string {
		return 'https://search.google.com/test/rich-results?url=' . rawurlencode( $url );
	}
}
