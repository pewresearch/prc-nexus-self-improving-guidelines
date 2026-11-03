import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { createSettingsClient } from '@prc/components';

import { store } from './store';
import type {
	ApiResponse,
	BulkInjectResponse,
	EditorialMemory,
	InjectResponse,
	MemoriesResponse,
	MemoryScope,
	MemoryStatus,
	Settings,
} from './types';

const REST_PATH = '/prc-nexus-self-improving-guidelines/v1';

export const { fetchSettings, saveSettings } = createSettingsClient<
	Settings,
	ApiResponse
>({
	restPath: `${REST_PATH}/settings`,
	store,
	successMessage: __(
		'Settings saved.',
		'prc-nexus-self-improving-guidelines'
	),
});

export async function fetchMemories(
	status: MemoryStatus = 'pending'
): Promise<EditorialMemory[]> {
	const response = (await apiFetch({
		path: `${REST_PATH}/memories?status=${encodeURIComponent(status)}`,
	})) as MemoriesResponse;

	return response.memories ?? [];
}

export async function createMemory(payload: {
	title: string;
	rule_text: string;
	scope: MemoryScope;
	block_name?: string;
	rationale?: string;
	source_post_id?: number;
}): Promise<EditorialMemory> {
	const response = (await apiFetch({
		path: `${REST_PATH}/memories`,
		method: 'POST',
		data: payload,
	})) as { memory: EditorialMemory };

	return response.memory;
}

export async function updateMemory(
	id: number,
	fields: Partial<
		Pick<
			EditorialMemory,
			'title' | 'rule_text' | 'scope' | 'block_name' | 'rationale'
		>
	>
): Promise<EditorialMemory> {
	const response = (await apiFetch({
		path: `${REST_PATH}/memories/${id}`,
		method: 'POST',
		data: fields,
	})) as { memory: EditorialMemory };

	return response.memory;
}

export async function injectMemory(id: number): Promise<InjectResponse> {
	return (await apiFetch({
		path: `${REST_PATH}/memories/${id}/inject`,
		method: 'POST',
	})) as InjectResponse;
}

export async function rejectMemory(id: number): Promise<EditorialMemory> {
	const response = (await apiFetch({
		path: `${REST_PATH}/memories/${id}/reject`,
		method: 'POST',
	})) as { memory: EditorialMemory };

	return response.memory;
}

export async function injectMemories(
	ids: number[]
): Promise<BulkInjectResponse> {
	return (await apiFetch({
		path: `${REST_PATH}/memories/inject`,
		method: 'POST',
		data: { ids },
	})) as BulkInjectResponse;
}
