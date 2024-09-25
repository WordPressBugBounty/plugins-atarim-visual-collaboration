import React from 'react'
import InstallAtarim from './InstallAtarim'
import { getData } from './services/apiResquest'
import Sidebar from './SideBar'
import "./style/sidebar.css"
import TaskContent from './TaskContent'


export default class ExampleAtarimPanel extends React.Component {
  constructor (props) {
    super(props)
    this.state = {
      isSelectTask: false,
      taskData: {},
      taskList: [],
      selectedTask: null,
      isLoadingTask: false,
      wpUserId: null,
      userPlans: {},
      isNoSiteId: false,
      isNotlicense: false,
      pluginData: {},
    }

    this.handleLayoutChange = this.handleLayoutChange.bind(this)
    this.setContentLengthState = this.setContentLengthState.bind(this)
  }

  async componentDidMount () {
    window.vcwbEditorApi.subscribe('layoutChange', this.handleLayoutChange)
    window.vcwbEditorApi.subscribe('elementUpdate', this.handleLayoutChange)
    this.getCookie("wordpress_manage_ip")
    const params = new Proxy(new URLSearchParams(window.location.search), {
      get: (searchParams, prop) => searchParams.get(prop),
    });
    // Get the value of "some_key" in eg "https://example.com/?some_key=some_value"
    let pageId = params.post; // "some_value"
    let baseUrl = window.location.origin;
    this.setState({
      isLoadingTask: true,
    })
    await getData(`${baseUrl}/?rest_route=/atarim/v1/db/vc`).then( res=>{
      this.setState({
        userPlans: res?.wpf_user_plan,
        pluginData:res
      })
      if (res?.wpf_site_id) {
        if (!res.wpf_license || res.wpf_license === "invalid" ) {
          this.setState({
            isLoadingTask: false,
            isNotlicense: true
          })
        } else {
        getData(`https://api.atarim.io/vc/all/task?wpf_site_id=${res?.wpf_site_id}&current_page_id=${pageId}`)
        .then( data=>{
          this.setState({
            taskData: data,
            taskList: data.data,
            isLoadingTask: false,
          })
        })
      }
      } else {
        this.setState({
          isNoSiteId: true,
          isLoadingTask: false,
        })
      }
    });
  }

  componentWillUnmount () {
    window.clearInterval(this.layoutChangeInterval)
    window.vcwbEditorApi.unsubscribe('layoutChange', this.handleLayoutChange)
    window.vcwbEditorApi.unsubscribe('elementUpdate', this.handleLayoutChange)
  }

  handleLayoutChange () {
    this.layoutChangeInterval = window.setInterval(this.setContentLengthState, 1100)
  }

  setContentLengthState () {
    this.setState({ contentLength: this.props.getContentLength() })
    window.clearInterval(this.layoutChangeInterval)
  }

  getCookie (name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return this.setState({
      wpUserId: parts.pop().split(';').shift()
    }) 
  }

  handleClickTask = (taskValue) => {
    this.setState({
      isSelectTask: !this.state.isSelectTask,
      selectedTask: taskValue
    })
  }

  handleSetSearchedTask = (data) => {
    this.setState({
      taskList: data
    })
  }

  handleUpdateTaskComment = (data,taskUpate,type,internal) => {
    let newComment = this.state.selectedTask.task.comments.concat(data)
    let commentUpdate = this.state.taskList.map(each => each.task.id === data.task_id ? 
      {...each, task:{...each.task, comments:newComment}} :each
      )
    this.setState({
      selectedTask: {task:{...this.state.selectedTask.task, comments:newComment}},
      taskList:commentUpdate
    })
    if (taskUpate) {
      if (type === "status") {
        let newStatus = this.state.taskList.map(each => each.task.id === data.task_id ? 
          {...each, task:{...each.task, task_status:taskUpate, comments:newComment}} :each
          )
        this.setState({
          selectedTask: {task:{...this.state.selectedTask.task, task_status:taskUpate,comments:newComment}},
          taskList:newStatus
      }) 
      }else if (type==="priority"){
        let newPriority = this.state.taskList.map(each => each.task.id === data.task_id ? 
          {...each, task:{...each.task, task_priority:taskUpate, comments:newComment}} :each
          )
        this.setState({
          selectedTask: {task:{...this.state.selectedTask.task, task_priority:taskUpate,comments:newComment}},
          taskList:newPriority
      }) 
      }else if (type==="user"){
        let newUser = this.state.taskList.map(each => each.task.id === data? 
          {...each, task:{...each.task, task_notify_users:internal}} :each
          )
        this.setState({
          selectedTask: {task:{...this.state.selectedTask.task, task_notify_users:internal}},
          taskList:newUser
      }) 
      }
      else if (type==="internal"){
        this.setState({
          selectedTask: {task:{...this.state.selectedTask.task, is_internal:internal,comments:newComment}}
      }) 
      }
      }
  }

  
  render () {
    return (
      <div className='atarimWrapper'>
        {/* <InstallAtarim/> */}
        {
          this.state.isSelectTask ?
          <TaskContent
            isSelectTask =    {this.state.isSelectTask}
            handleClickTask=  {this.handleClickTask}  
            selectedTask=     {this.state.selectedTask.task}
            handleStartPost=  {this.handleStartPost}
            handleUpdateTaskComment=  {this.handleUpdateTaskComment}
            wpUserId=          {this.state.wpUserId}
            userPlans=        {this.state.userPlans}
          />:
          <Sidebar
            isSelectTask =    {this.state.isSelectTask}
            handleClickTask=  {this.handleClickTask}  
            taskList=         {this.state.taskList || []}
            taskData=         {this.state.taskData}
            handleSetSearchedTask=  {this.handleSetSearchedTask}
            isLoadingTask=    {this.state.isLoadingTask}
            isNoSiteId=       {this.state.isNoSiteId}
            isNotlicense=     {this.state.isNotlicense}
            pluginData=       {this.state.pluginData}
          />
        }
      </div>
    )
  }
}