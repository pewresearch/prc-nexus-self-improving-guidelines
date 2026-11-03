export type MemoryStatus = 'pending' | 'rejected' | 'injected';

export type MemoryScope = 'site' | 'copy' | 'images' | 'additional' | 'blocks';

export interface Settings {
	enabled: boolean;
}

export interface ApiResponse {
	settings: Settings;
}

export interface SettingsStoreState {
	settings: Settings;
	isLoaded: boolean;
}

export interface EditorialMemory {
	id: number;
	title: string;
	rule_text: string;
	rationale: string;
	scope: MemoryScope;
	block_name: string;
	status: MemoryStatus;
	source_post_id: number;
	source_post_title: string;
	source_post_url: string;
	source_comment_ids: number[];
	injected_guideline_id: number;
	harvested_at: string;
}

export interface MemoriesResponse {
	memories: EditorialMemory[];
}

export interface InjectResponse {
	memory: EditorialMemory;
	guideline_id: number;
}

export interface BulkInjectResponse {
	memories: EditorialMemory[];
	errors: Array<{ id: number; code: string; message: string }>;
}

export const MEMORY_SCOPES: Array<{ label: string; value: MemoryScope }> = [
	{ label: 'Site', value: 'site' },
	{ label: 'Copy', value: 'copy' },
	{ label: 'Images', value: 'images' },
	{ label: 'Additional', value: 'additional' },
	{ label: 'Blocks', value: 'blocks' },
];
