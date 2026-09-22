import { useCallback } from 'react';
import { useToast } from '@/components/ToastProvider';

/**
 * Copy-to-clipboard with a fallback for browsers (and insecure origins) where
 * the async Clipboard API is unavailable.
 */
export function useCopyToClipboard(): (text: string, label?: string) => Promise<void> {
  const toast = useToast();

  return useCallback(
    async (text: string, label = 'Copied to clipboard') => {
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(text);
        } else {
          const textarea = document.createElement('textarea');
          textarea.value = text;
          textarea.setAttribute('readonly', '');
          textarea.style.position = 'fixed';
          textarea.style.opacity = '0';
          document.body.appendChild(textarea);
          textarea.select();
          document.execCommand('copy');
          document.body.removeChild(textarea);
        }

        toast.success(label);
      } catch {
        toast.error('Copying is not available in this browser. Select the text and copy manually.');
      }
    },
    [toast],
  );
}
