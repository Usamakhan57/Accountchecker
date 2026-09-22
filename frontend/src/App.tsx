import { BrowserRouter } from 'react-router-dom';
import { ToastProvider } from '@/components/ToastProvider';
import { AuthProvider } from '@/context/AuthContext';
import { AppRoutes } from '@/router';

export function App() {
  return (
    <BrowserRouter>
      <ToastProvider>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </ToastProvider>
    </BrowserRouter>
  );
}
