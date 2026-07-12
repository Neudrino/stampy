declare module '@wordpress/plugins' {
	export function registerPlugin(
		name: string,
		settings: {
			render: () => JSX.Element | null;
			icon?: string;
		}
	): void;
}

declare module '@wordpress/editor' {
	import type { ComponentType } from 'react';

	export interface PluginSidebarProps {
		name: string;
		title: string;
		icon?: string;
		children?: React.ReactNode;
	}

	export const PluginSidebar: ComponentType< PluginSidebarProps >;
}

declare module '@wordpress/data' {
	interface StoreSelectors {
		( store: string ): Record< string, any >;
	}

	type SelectorFn = ( select: StoreSelectors ) => any;

	export function useSelect( selector: SelectorFn ): any;

	export function useDispatch(
		store: string
	): Record< string, ( ...args: any[] ) => any >;
}

declare module '@wordpress/element' {
	export function useState< T >( initial: T ): [ T, ( value: T ) => void ];

	export function useEffect( callback: () => void, deps?: unknown[] ): void;

	export function useRef< T >( initial: T ): { current: T };
}
