/**
 * Ambient type declarations for data localized from PHP to JavaScript.
 *
 * The `window.stampy` object is populated on the PHP side (e.g. via
 * `wp_localize_script()` / `wp_add_inline_script()`). This declaration is
 * kept in sync MANUALLY with the PHP code that emits it — there is no
 * automatic generation, so update this file whenever the localized payload
 * changes on the PHP side.
 */

declare global {
	interface StampyList {
		id: number;
		name: string;
		slug: string;
		description: string;
	}

	interface StampyQuizQuestion {
		question: string;
		answer: string;
	}

	interface StampyField {
		key: string;
		label: string;
		type: string;
		options: string[] | null;
		required: boolean;
	}

	interface StampyGlobal {
		restUrl: string;
		restNonce: string;
		lists: StampyList[];
		fields?: StampyField[];
		consentText: string;
		quizQuestions?: StampyQuizQuestion[];
		turnstileEnabled?: boolean;
		turnstileSiteKey?: string;
		friendlyCaptchaEnabled?: boolean;
		friendlyCaptchaSiteKey?: string;
		previewUrl?: string;
		ajaxUrl?: string;
		startSendNonce?: string;
		cancelSendNonce?: string;
		progressNonce?: string;
	}

	interface Window {
		stampy?: StampyGlobal;
	}
}

export {};
