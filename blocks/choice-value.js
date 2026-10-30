/**
 * The stored value of a choice written as just a label ("Home delivery" -> home_delivery).
 *
 * Plain JS with no imports so the visual builder, the block editor and the tests
 * share one rule.
 *
 * toLowerCase() turns the Turkish capital İ into "i" plus a combining dot above
 * (U+0307), so "İstanbul" became a value that looks like "istanbul" but never
 * equals it: a condition typed as istanbul could not match. Drop that dot.
 */
export function choiceValue( text ) {
	return String( text || "" )
		.toLowerCase()
		.replace( /i\u0307/g, "i" )
		.replace( /\s+/g, "_" );
}
