import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import logo from '../../images/logo.png';

interface AuthLayoutProps {
    title: string;
    children: ReactNode;
}

export function AuthLayout({ title, children }: AuthLayoutProps) {
    return (
        <>
            <Head title={title} />
            <main className="mx-auto flex w-full max-w-[25rem] flex-col gap-8 px-4 py-16">
                <div className="flex items-center gap-3">
                    <img src={logo} alt="" className="size-8" />
                    <p className="font-display text-xl font-bold text-text">TikaroChat</p>
                </div>
                <h1 className="font-display text-2xl font-semibold text-text">{title}</h1>
                {children}
            </main>
        </>
    );
}
