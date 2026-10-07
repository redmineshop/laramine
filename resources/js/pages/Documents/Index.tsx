import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type DocumentGroup = {
    key: string;
    document_ids: number[];
};

type CategoryOption = {
    id: number;
    name: string;
};

type DocumentsIndexProps = {
    projectId: number;
    sortBy: string;
    groups: DocumentGroup[];
    categories: CategoryOption[];
    canAdd: boolean;
};

const sorts = ['category', 'date', 'title', 'author'] as const;

export default function DocumentsIndex({
    projectId,
    sortBy,
    groups,
    categories,
    canAdd,
}: DocumentsIndexProps) {
    const form = useForm({
        title: '',
        category_id: categories[0]?.id ?? 0,
        description: '',
    });

    function submit(event: FormEvent<HTMLFormElement>): void {
        event.preventDefault();
        form.post(`/projects/${projectId}/documents`);
    }

    return (
        <>
            <Head title="Documents" />
            <main className="mx-auto flex min-h-svh max-w-3xl flex-col gap-4 bg-background p-6 text-foreground">
                <Card>
                    <CardHeader>
                        <CardTitle>Documents</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <p className="text-sm text-muted-foreground">
                            Minimal grouped list. This is not a Redmine screen and not a 0.1 release.
                        </p>
                        <p className="flex flex-wrap gap-3 text-sm">
                            {sorts.map((sort) => (
                                <Link
                                    key={sort}
                                    href={`/projects/${projectId}/documents?sort_by=${sort}`}
                                    className={sort === sortBy ? 'font-medium underline' : 'underline-offset-4 hover:underline'}
                                >
                                    {sort}
                                </Link>
                            ))}
                        </p>
                        {groups.length === 0 ? <p className="text-sm">No documents.</p> : null}
                        {groups.map((group) => (
                            <section key={group.key}>
                                <h2 className="text-sm font-medium">{group.key}</h2>
                                <p className="text-sm text-muted-foreground">{group.document_ids.join(', ')}</p>
                            </section>
                        ))}
                    </CardContent>
                </Card>
                {canAdd ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Add document</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form className="flex flex-col gap-3" onSubmit={submit}>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="title">Title</Label>
                                    <Input
                                        id="title"
                                        value={form.data.title}
                                        onChange={(event) => form.setData('title', event.target.value)}
                                    />
                                </div>
                                <div className="flex flex-col gap-1">
                                    <Label htmlFor="category_id">Category</Label>
                                    <select
                                        id="category_id"
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                        value={form.data.category_id}
                                        onChange={(event) => form.setData('category_id', Number(event.target.value))}
                                    >
                                        {categories.map((category) => (
                                            <option key={category.id} value={category.id}>
                                                {category.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <Button type="submit" disabled={form.processing}>
                                    Save
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                ) : null}
            </main>
        </>
    );
}
