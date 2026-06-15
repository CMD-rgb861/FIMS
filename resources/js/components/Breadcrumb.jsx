export default function Breadcrumb({ items }) {
    return (
        <div className="h-16 bg-white border-b border-slate-200 flex items-center px-6">
            <div className="text-sm text-slate-500 flex items-center gap-2">
                {items.map((item, index) => (
                    <div key={index} className="flex items-center gap-2">
                        {index > 0 && <span className="text-slate-300">›</span>}
                        {item.url ? (
                            <a href={item.url} className="hover:text-slate-700">
                                {item.label}
                            </a>
                        ) : (
                            <span className="text-slate-700 font-medium">
                                {item.label}
                            </span>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}