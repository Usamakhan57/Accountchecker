/**
 * Document head management.
 *
 * Marketing pages set a title, description and canonical URL. Authenticated
 * pages additionally set robots=noindex so a shared screenshot or a leaked URL
 * never puts dashboard content in a search index.
 */

export interface PageMeta {
  title: string;
  description?: string;
  canonicalPath?: string;
  noIndex?: boolean;
}

function upsertMeta(selector: string, attributes: Record<string, string>): void {
  let element = document.head.querySelector<HTMLMetaElement>(selector);

  if (!element) {
    element = document.createElement('meta');
    document.head.appendChild(element);
  }

  for (const [name, value] of Object.entries(attributes)) {
    element.setAttribute(name, value);
  }
}

function upsertCanonical(href: string): void {
  let link = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');

  if (!link) {
    link = document.createElement('link');
    link.rel = 'canonical';
    document.head.appendChild(link);
  }

  link.href = href;
}

export function applyPageMeta({ title, description, canonicalPath, noIndex }: PageMeta): void {
  const fullTitle = title === 'AccountCheck' ? title : `${title} · AccountCheck`;
  document.title = fullTitle;

  if (description) {
    upsertMeta('meta[name="description"]', { name: 'description', content: description });
    upsertMeta('meta[property="og:description"]', { property: 'og:description', content: description });
  }

  upsertMeta('meta[property="og:title"]', { property: 'og:title', content: fullTitle });
  upsertMeta('meta[name="robots"]', {
    name: 'robots',
    content: noIndex ? 'noindex, nofollow' : 'index, follow',
  });

  if (canonicalPath) {
    upsertCanonical(new URL(canonicalPath, window.location.origin).toString());
    upsertMeta('meta[property="og:url"]', {
      property: 'og:url',
      content: new URL(canonicalPath, window.location.origin).toString(),
    });
  }
}
