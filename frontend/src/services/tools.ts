import { request } from '@/services/apiClient';
import type { DuplicateMode, DuplicateReport, NameStyle, NameSuggestions } from '@/types/api';

/**
 * The free tools.
 *
 * Neither costs credits, because neither verifies anything: they work on the
 * list the user already has. The name generator in particular makes no claim
 * about whether a name is taken — that is a question only a checker can
 * answer, and the response says so.
 */

export function findDuplicates(
  input: string,
  mode: DuplicateMode = 'exact',
  checker?: string,
): Promise<DuplicateReport> {
  return request<DuplicateReport>('/api/tools/duplicates', {
    method: 'POST',
    body: { input, mode, checker: mode === 'checker' ? checker : undefined },
  });
}

export function generateNames(options: {
  words: string;
  styles: NameStyle[];
  count: number;
  platform?: string;
  seed?: number;
}): Promise<NameSuggestions> {
  return request<NameSuggestions>('/api/tools/name-generator', {
    method: 'POST',
    body: {
      words: options.words,
      styles: options.styles,
      count: options.count,
      platform: options.platform || undefined,
      seed: options.seed,
    },
  });
}
