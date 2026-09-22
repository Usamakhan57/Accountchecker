import { useEffect } from 'react';
import { applyPageMeta } from '@/utils/seo';
import type { PageMeta } from '@/utils/seo';

/**
 * Sets the document head for a route. Authenticated pages pass noIndex.
 */
export function usePageMeta(meta: PageMeta): void {
  const { title, description, canonicalPath, noIndex } = meta;

  useEffect(() => {
    applyPageMeta({ title, description, canonicalPath, noIndex });
  }, [title, description, canonicalPath, noIndex]);
}
