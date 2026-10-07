import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type LoginPageProps = {
    submitUrl: string;
};

type LoginForm = {
    login: string;
    password: string;
};

export default function Login({ submitUrl }: LoginPageProps) {
    const form = useForm<LoginForm>({
        login: '',
        password: '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(submitUrl);
    }

    return (
        <>
            <Head title="Sign in" />
            <main className="flex min-h-svh items-center justify-center bg-background p-6 text-foreground">
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <CardTitle>Sign in</CardTitle>
                        <CardDescription>
                            Session sign-in for this install. Redmine UX parity
                            is not claimed. This is not a 0.1 release.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form className="flex flex-col gap-4" onSubmit={submit}>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="login">Login or email</Label>
                                <Input
                                    id="login"
                                    name="login"
                                    type="text"
                                    autoComplete="username"
                                    value={form.data.login}
                                    onChange={(event) =>
                                        form.setData('login', event.target.value)
                                    }
                                    aria-invalid={Boolean(form.errors.login)}
                                    required
                                />
                                {form.errors.login ? (
                                    <p className="text-sm text-destructive">
                                        {form.errors.login}
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData(
                                            'password',
                                            event.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(form.errors.password)}
                                    required
                                />
                                {form.errors.password ? (
                                    <p className="text-sm text-destructive">
                                        {form.errors.password}
                                    </p>
                                ) : null}
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                Sign in
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </main>
        </>
    );
}
