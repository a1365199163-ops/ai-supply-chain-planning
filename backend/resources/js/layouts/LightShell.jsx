import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ToastProvider } from '@openmes/ui';
import { LayoutDashboard, ClipboardList, Activity, CircleAlert, BookOpen, Settings2, ArrowUpRight, LogOut, Menu, Sailboat, Sparkles } from 'lucide-react';
import { LiveShapesProvider } from '../components/LiveShapesProvider';
import '../../css/light-production.css';

export function LightBrand() {
    return <span className="lp-brand"><span className="lp-mark"><Sailboat size={25}/></span><span>轻舟<small>生产协同 · FLOW</small></span></span>;
}

export default function LightShell({ children }) {
    const { props, url } = usePage();
    const [open, setOpen] = useState(false);
    const { auth, csrf_token, flash, enabledModules, knowledgeAssistEnabled } = props;
    const links = [
        ['生产概览', '/admin/dashboard', LayoutDashboard],
        ['AI 供应链计划', '/admin/ai-planning', Sparkles],
        ['工单中心', '/admin/work-orders', ClipboardList],
        ['生产进度', '/admin/shift-monitor', Activity],
        ...(enabledModules?.includes('quality') ? [['异常处理', '/admin/issues', CircleAlert]] : []),
        ...(knowledgeAssistEnabled ? [['知识辅助', '/modules/knowledge-assist', BookOpen, true]] : []),
    ];
    return <LiveShapesProvider><ToastProvider dismissLabel="关闭"><div className="lp-app">
        <aside className={`lp-sidebar ${open ? 'is-open' : ''}`}>
            <Link href="/admin/dashboard" className="lp-brand-link" onClick={()=>setOpen(false)}><LightBrand/></Link>
            <div className="lp-nav-label">日常工作 / WORKSPACE</div>
            <nav aria-label="主导航">{links.map(([label,href,Icon,external])=> {
                const classes = `lp-nav-link ${url.split('?')[0].startsWith(href) ? 'active' : ''}`;
                const content=<><Icon size={19}/><span>{label}</span>{external && <ArrowUpRight size={14}/>}</>;
                return external ? <a key={href} className={classes} href={href}>{content}</a> : <Link key={href} className={classes} href={href} onClick={()=>setOpen(false)}>{content}</Link>;
            })}</nav>
            <details className="lp-config"><summary><Settings2 size={17}/>基础配置</summary>
                <Link href="/admin/product-types">产品与工艺</Link><Link href="/admin/lines">产线与工位</Link><Link href="/admin/users">成员与权限</Link><Link href="/settings">系统设置</Link>
            </details>
            <div className="lp-sidebar-bottom"><span className="lp-live-dot"/>生产计划与供应协同
                <p>让物料需求看得见，<br/>让生产交付有依据。</p>
                <a href="/light-production-about.html" target="_blank" rel="noreferrer">关于与开源说明 ↗</a>
            </div>
        </aside>
        {open && <button aria-label="关闭导航" className="lp-backdrop" onClick={()=>setOpen(false)}/>}
        <section className="lp-workspace">
            <header className="lp-topbar"><button className="lp-menu-button" aria-label="打开导航" onClick={()=>setOpen(!open)}><Menu size={22}/></button><span className="lp-top-caption">工作空间 <span>/</span> {links.find(([,href])=>url.startsWith(href))?.[0] || '基础配置'}</span><div className="lp-user"><span className="lp-avatar">{auth?.user?.name?.slice(0,1) || 'A'}</span><span>{auth?.user?.name}</span><form action="/logout" method="POST"><input type="hidden" name="_token" value={csrf_token}/><button aria-label="退出登录" title="退出登录"><LogOut size={17}/></button></form></div></header>
            <main className="lp-main">{flash?.success && <div role="status" className="lp-flash">{flash.success}</div>}{flash?.error && <div role="alert" className="lp-flash error">{flash.error}</div>}{children}</main>
        </section>
    </div></ToastProvider></LiveShapesProvider>;
}
