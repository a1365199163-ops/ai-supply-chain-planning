import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, RefreshCw, Plus, BookOpen, ClipboardList, Sparkles } from 'lucide-react';
import AppLayout from '../../layouts/AppLayout';

const statusLabels={PENDING:'待接单',ACCEPTED:'已接单',IN_PROGRESS:'生产中',BLOCKED:'受阻',PAUSED:'已暂停',CHANGE_HOLD:'变更暂停'};
export default function LightDashboard({overview,recentOrders,recentIssues,refreshedAt}) {
 const {knowledgeAssistEnabled}=usePage().props;
 return <><Head title="生产概览 · 智造协同"/><div className="lp-dashboard">
  <div className="lp-page-heading"><div><div className="lp-eyebrow">PRODUCTION & SUPPLY CHAIN / OVERVIEW</div><h1>把生产计划与供应风险放在一起看。</h1><p>从物料需求到工单进度，快速定位需要跟进的事项。</p></div><button className="lp-button secondary" onClick={()=>router.reload({only:['overview','recentOrders','recentIssues','refreshedAt']})}><RefreshCw size={15}/>刷新数据</button></div>
  <div className="lp-metrics">{[['进行中的工单',overview.active,'未完成 / 未取消'],['正在生产',overview.running,'已进入执行阶段'],['受阻工单',overview.blocked,'等待处置或变更'],['待处理异常',overview.issues ?? '—','未处理 / 已确认']].map(([title,value,caption],i)=><div className="lp-metric" key={title}><div><span>{title}</span><span className="lp-metric-index">0{i+1}</span></div><strong>{value}</strong><small>{caption}</small></div>)}</div>
  <div className="lp-board-grid"><section className="lp-panel"><div className="lp-panel-heading"><div><span className="lp-eyebrow">ORDER FLOW</span><h2>工单推进</h2></div><Link href="/admin/work-orders">全部工单 <ArrowRight size={15}/></Link></div>
   {recentOrders.length ? <div className="lp-order-list">{recentOrders.map(o=>{const progress=o.planned_qty>0?Math.min(100,Math.round(o.produced_qty/o.planned_qty*100)):0;return <Link className="lp-order" href={`/admin/work-orders/${o.id}`} key={o.id}><div><strong>{o.order_no}</strong><span className="lp-status">{statusLabels[o.status] || o.status}</span></div><div className="lp-progress"><span style={{width:`${progress}%`}}/></div><small>{o.produced_qty} / {o.planned_qty} 件 <b>{progress}%</b></small></Link>})}</div> : <div className="lp-empty"><span className="lp-empty-icon"><ClipboardList size={30}/></span><h3>从第一张工单开始</h3><p>先配置产品和产线，再建立工单。<br/>这里会展示实际的生产进度。</p><Link className="lp-button" href="/admin/work-orders/create"><Plus size={16}/>新建工单</Link><Link className="lp-text-link" href="/admin/product-types">配置产品与工艺 →</Link></div>}
   <div className="lp-panel-foot">数据更新于 {refreshedAt} · 点击刷新获取最新进度</div>
  </section><div className="lp-right-column"><section className="lp-ai-card"><span className="lp-eyebrow">AI SUPPLY CHAIN</span><Sparkles size={25}/><h2>提前看见物料与交期风险。</h2><p>结合工单、BOM 与库存，查看未来 30 天净需求和交期关注项。</p><Link href="/admin/ai-planning">打开计划助手 <ArrowRight size={16}/></Link><small>MRP 需求计算 · 工单交付关注 · 建议附数据依据</small></section><section className="lp-knowledge-card"><span className="lp-eyebrow">KNOWLEDGE ASSIST</span><BookOpen size={27}/><h2>遇到异常，<br/>先找到排查依据。</h2><p>描述现象或输入报警代码，检索相关知识、来源与处理建议。</p>{knowledgeAssistEnabled ? <a href="/modules/knowledge-assist">打开知识辅助 <ArrowRight size={17}/></a>:<p>知识辅助模块尚未启用</p>}<small>8 篇教学知识 · 12 个模拟场景</small></section>
  <section className="lp-panel lp-issues"><div className="lp-panel-heading"><h2>待处理异常</h2>{overview.issues!==null && <Link href="/admin/issues">查看 <ArrowRight size={14}/></Link>}</div>{recentIssues.length?recentIssues.map(i=><Link key={i.id} href="/admin/issues" className="lp-issue-row"><span className="lp-issue-dot"/><span>{i.title}</span><ArrowRight size={14}/></Link>):<p className="lp-no-issues">{overview.issues===null?'异常功能当前未启用。':'暂无待处理异常。新上报的异常会出现在这里。'}</p>}</section></div></div>
  <div className="lp-workflow"><span>计划协同路径</span><Link href="/admin/ai-planning">01 分析供需</Link><ArrowRight size={15}/><Link href="/admin/work-orders">02 确认工单</Link><ArrowRight size={15}/><Link href="/admin/shift-monitor">03 跟进生产</Link><ArrowRight size={15}/><span>04 处理异常</span></div>
 </div></>;
}
LightDashboard.layout=page=><AppLayout>{page}</AppLayout>;

